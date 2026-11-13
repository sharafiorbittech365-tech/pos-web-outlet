<?php

declare(strict_types=1);

namespace App\Modules\Websites\Services;

use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Config\OmniPOS;
use OmniPOS\Core\Exceptions\NotFoundException;

/**
 * Websites & domains as platform routing records only: site mode, address, custom domains, SSL and
 * the marketplace flag. Store content, API keys and orders stay with the business.
 */
final class WebsiteDirectory
{
    public const FILTERS = ['all', 'storefront', 'api', 'off', 'pending', 'ssl'];

    /** @param array{mode?: string, tenant?: int} $filters */
    public static function listQuery(array $filters): BaseBuilder
    {
        $b = db_connect()->table('sites s')
            ->select('s.id, s.tenant_id, s.business_id, s.mode, s.slug, s.theme, s.marketplace_listed, s.published_at, b.name AS store_name, t.name AS tenant_name')
            ->select("GROUP_CONCAT(CONCAT_WS('|', d.id, d.host, d.status, d.is_primary, d.ssl_status, IFNULL(d.ssl_expires_at, ''), d.hosting, d.is_enabled, d.admin_blocked) ORDER BY d.is_primary DESC, d.host SEPARATOR ';') AS domains", false)
            ->join('businesses b', 'b.id = s.business_id')
            ->join('tenants t', 't.id = s.tenant_id')
            ->join('site_domains d', 'd.site_id = s.id', 'left')
            ->where('t.deleted_at', null)
            ->groupBy('s.id');
        $f = $filters['mode'] ?? 'all';
        if (in_array($f, ['storefront', 'api', 'off'], true)) {
            $b->where('s.mode', $f);
        } elseif ($f === 'pending') {
            $b->whereIn('s.id', static fn (BaseBuilder $q): BaseBuilder => $q->select('site_id')->from('site_domains')->where('status !=', 'active'));
        } elseif ($f === 'ssl') {
            $b->whereIn('s.id', static fn (BaseBuilder $q): BaseBuilder => $q->select('site_id')->from('site_domains')->where('status', 'active')
                ->where('ssl_expires_at <', date('Y-m-d H:i:s', strtotime('+35 days'))));
        }
        if (! empty($filters['tenant'])) {
            $b->where('s.tenant_id', (int) $filters['tenant']);
        }

        return $b;
    }

    /** @return list<array{id: int, host: string, status: string, primary: bool, ssl: string, expires: string, hosting: string, enabled: bool, blocked: bool}> */
    public static function domains(?string $packed): array
    {
        if ($packed === null || $packed === '') {
            return [];
        }

        return array_map(static function (string $d): array {
            [$id, $host, $status, $primary, $ssl, $exp, $hosting, $enabled, $blocked] = array_pad(explode('|', $d), 9, '');

            return ['id' => (int) $id, 'host' => $host, 'status' => $status, 'primary' => $primary === '1', 'ssl' => $ssl, 'expires' => $exp,
                'hosting' => $hosting !== '' ? $hosting : 'platform', 'enabled' => $enabled !== '0', 'blocked' => $blocked === '1'];
        }, explode(';', $packed));
    }

    public static function address(array $site): string
    {
        foreach (self::domains($site['domains'] ?? null) as $d) {
            if ($d['primary'] && $d['status'] === 'active') {
                return $d['host'];
            }
        }

        return $site['slug'] . '.' . config(OmniPOS::class)->siteHost;
    }

    /** @return array{storefront: int, api: int, domains: int, pending: int, ssl_soon: int, listed: int} */
    public static function stats(): array
    {
        $db = db_connect();
        $sites = $db->table('sites s')->select("COALESCE(SUM(s.mode = 'storefront'), 0) AS storefront, COALESCE(SUM(s.mode = 'api'), 0) AS api, COALESCE(SUM(s.marketplace_listed = 1), 0) AS listed", false)
            ->join('tenants t', 't.id = s.tenant_id')->where('t.deleted_at', null)->get()->getRowArray();
        $soon = $db->escape(date('Y-m-d H:i:s', strtotime('+35 days')));
        $doms = $db->table('site_domains d')->select("COALESCE(SUM(d.status = 'active'), 0) AS domains, COALESCE(SUM(d.status != 'active'), 0) AS pending,
                COALESCE(SUM(d.status = 'active' AND d.ssl_expires_at < {$soon}), 0) AS ssl_soon", false)
            ->join('sites s', 's.id = d.site_id')->join('tenants t', 't.id = s.tenant_id')->where('t.deleted_at', null)->get()->getRowArray();

        return array_map('intval', $sites + $doms);
    }

    /**
     * Re-check one custom domain: DNS (TXT _omnipos-verify.<host> = token, or a CNAME to the site host)
     * and SSL expiry. Only moves a pending / failed domain to active when DNS proves it (an unverified
     * domain keeps its status); marks an
     * expired certificate as expired. Never deletes anything.
     *
     * @param array{name: string, role: string, id?: int|null} $actor
     * @return array{host: string, before: string, after: string, dns: bool, ssl: string}
     */
    public static function recheck(int $domainId, array $actor): array
    {
        $db = db_connect();
        $d = $db->table('site_domains d')->select('d.*, s.tenant_id, s.slug')->join('sites s', 's.id = d.site_id')->where('d.id', $domainId)->get()->getRowArray()
            ?? throw new NotFoundException('Domain not found.');
        $siteHost = config(OmniPOS::class)->siteHost;
        $dns = self::dnsVerified((string) $d['host'], (string) $d['verify_token'], $siteHost);
        $now = date('Y-m-d H:i:s');
        $upd = [];
        if ($dns && $d['status'] !== 'active') {
            $upd += ['status' => 'active', 'verified_at' => $now];
            if ($d['ssl_status'] === 'none') {
                $upd['ssl_status'] = 'pending';
            }
        }
        if ($d['ssl_status'] === 'active' && $d['ssl_expires_at'] !== null && $d['ssl_expires_at'] < $now) {
            $upd['ssl_status'] = 'expired';
        }
        if ($upd !== []) {
            $db->table('site_domains')->where('id', $domainId)->update($upd);
        }
        $after = $upd['status'] ?? $d['status'];
        $ssl = $upd['ssl_status'] ?? $d['ssl_status'];
        service('audit')->platform('Domain re-checked', $d['host'] . ' · DNS ' . ($dns ? 'ok' : 'not verified') . ' · ' . $d['status'] . ' → ' . $after . ' · SSL ' . $ssl, (int) $d['tenant_id'], $actor);

        return ['host' => (string) $d['host'], 'before' => (string) $d['status'], 'after' => (string) $after, 'dns' => $dns, 'ssl' => (string) $ssl];
    }

    private static function dnsVerified(string $host, string $token, string $siteHost): bool
    {
        if (! function_exists('dns_get_record') || ! preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return false;
        }
        $txt = @dns_get_record('_omnipos-verify.' . $host, DNS_TXT) ?: [];
        foreach ($txt as $r) {
            if (isset($r['txt']) && hash_equals($token, trim((string) $r['txt']))) {
                return true;
            }
        }
        $cname = @dns_get_record($host, DNS_CNAME) ?: [];
        foreach ($cname as $r) {
            if (isset($r['target']) && str_ends_with(strtolower((string) $r['target']), '.' . strtolower($siteHost))) {
                return true;
            }
        }

        return false;
    }
}
