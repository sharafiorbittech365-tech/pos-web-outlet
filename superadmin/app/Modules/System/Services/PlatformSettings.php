<?php

declare(strict_types=1);

namespace App\Modules\System\Services;

use CodeIgniter\CodeIgniter;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Config\OmniPOS;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Platform settings (platform_settings, JSON values) and system information for Super Admin › System.
 */
final class PlatformSettings
{
    /** Editable keys with their type. */
    public const KEYS = [
        'name' => 'string', 'supportEmail' => 'string', 'supportPhone' => 'string', 'trialDays' => 'int', 'trialPlan' => 'string',
        'graceDays' => 'int', 'vat' => 'float', 'signup' => 'bool', 'yearlyMonths' => 'int',
    ];

    /** Scheduled commands every deployment runs (crontab). */
    public const CRON = [
        ['* * * * *', 'POS servers', 'php spark omnipos:heartbeat', 'Report the POS server\'s health (online / load) to the platform.'],
        ['15 0 * * *', 'Super Admin', 'php spark omnipos:billing', 'Renewals, trial expiry and dunning (suspends accounts past the grace period).'],
        ['30 1 * * *', 'Super Admin', 'php spark omnipos:usage', 'Recompute usage counts (outlets, users, products, orders) for every tenant.'],
        ['45 2 * * *', 'Super Admin', 'php spark omnipos:purge-tenants', 'Erase the workspace data of accounts deleted longer ago than the retention period.'],
    ];

    /** @param array{name: string, role: string, id?: int|null} $actor */
    public function __construct(private readonly array $actor = [])
    {
    }

    /** @return array<string, mixed> */
    public static function current(): array
    {
        $catalog = service('catalog');
        $out = [];
        foreach (array_keys(self::KEYS) as $k) {
            $out[$k] = $catalog->setting($k);
        }

        return $out;
    }

    /** @param array<string, mixed> $in validated form fields */
    public function save(array $in): int
    {
        $catalog = service('catalog');
        if ($catalog->planByCode((string) $in['trialPlan']) === null) {
            throw new ValidationException('Choose the trial plan.', ['trialPlan' => 'Unknown plan.']);
        }
        $values = [];
        foreach (self::KEYS as $k => $type) {
            $v = $in[$k] ?? null;
            $values[$k] = match ($type) {
                'int' => (int) $v,
                'float' => (float) $v,
                'bool' => (string) $v === '1',
                default => trim((string) $v),
            };
        }
        $old = self::current();
        $changed = array_keys(array_filter($values, static fn ($v, $k): bool => (self::KEYS[$k] === 'float' ? (float) $old[$k] : $old[$k]) !== $v, ARRAY_FILTER_USE_BOTH));
        Tx::run(function (BaseConnection $db) use ($values, $changed): void {
            $now = date('Y-m-d H:i:s');
            $rows = [];
            foreach ($values as $k => $v) {
                array_push($rows, $k, json_encode($v), $now);
            }
            $db->query('INSERT INTO platform_settings (setting_key, value, updated_at) VALUES ' . implode(',', array_fill(0, count($values), '(?,?,?)'))
                . ' ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', $rows);
            service('audit')->platform('Settings changed', $changed ? implode(', ', $changed) : 'no change', null, $this->actor);
        });
        $catalog->flush();

        return count($changed);
    }

    /**
     * Brand & company: name, domain (Base URL), hosted-website domain, company name and address.
     * Saved in the shared brand file every app reads (OmniPOS\Ui\Brand) and mirrored in platform_settings.
     *
     * @param array<string, string> $in
     */
    public function saveBrand(array $in): void
    {
        $clean = static fn (string $k): string => trim((string) ($in[$k] ?? ''));
        $base = $clean('baseUrl');
        if ($base !== '') {
            $base = preg_match('#^https?://#i', $base) ? $base : 'https://' . $base;
            if (! preg_match('#^https?://[a-z0-9.-]+(:\d+)?/?$#i', $base)) {
                throw new ValidationException('Enter the domain like example.com.', ['baseUrl' => 'Use a domain such as omnipos.com (no path).']);
            }
        }
        $site = strtolower($clean('siteHost'));
        if ($site !== '' && ! preg_match('/^[a-z0-9.-]+$/', $site)) {
            throw new ValidationException('Website domain is not valid.', ['siteHost' => 'Letters, numbers, dots and hyphens only.']);
        }
        $values = ['name' => $clean('brandName'), 'baseUrl' => $base, 'siteHost' => $site, 'company' => $clean('company'), 'address' => $clean('address')];
        if (! \OmniPOS\Ui\Brand::save($values)) {
            throw new ValidationException('The shared folder is not writable.', ['brandName' => 'Set omnipos.sharedCachePath in .env to a writable folder shared by all apps.']);
        }
        Tx::run(function (BaseConnection $db) use ($values): void {
            $now = date('Y-m-d H:i:s');
            foreach (['brandName' => $values['name'], 'brandBaseUrl' => $values['baseUrl'], 'brandSiteHost' => $values['siteHost'], 'brandCompany' => $values['company'], 'brandAddress' => $values['address']] as $k => $v) {
                $db->query('INSERT INTO platform_settings (setting_key, value, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [$k, json_encode($v), $now]);
            }
            service('audit')->platform('Brand changed', $values['name'] . ' · ' . $values['baseUrl'], null, $this->actor);
        });
        service('catalog')->flush();
    }

    /** @return array<string, mixed> schema version, runtime and configuration facts */
    public static function info(): array
    {
        $db = db_connect();
        $mig = $db->table('migrations')->select('version, class, namespace, time, batch')->orderBy('version', 'desc')->orderBy('id', 'desc')->limit(1)->get()->getRowArray();
        $count = $db->table('migrations')->countAllResults();
        $cfg = config(OmniPOS::class);
        $cache = config(\Config\Cache::class);

        return [
            'schema' => $mig ? ['version' => $mig['version'], 'class' => substr((string) strrchr('\\' . $mig['class'], '\\'), 1), 'namespace' => $mig['namespace'],
                'at' => date('Y-m-d H:i:s', (int) $mig['time']), 'batch' => (int) $mig['batch'], 'count' => $count] : null,
            'app' => [
                'Environment' => ENVIRONMENT, 'PHP' => PHP_VERSION, 'CodeIgniter' => CodeIgniter::CI_VERSION, 'Database' => $db->getPlatform() . ' ' . $db->getVersion(),
                'Database name' => $db->getDatabase(), 'Cache handler' => $cache->handler, 'Time zone' => date_default_timezone_get(), 'OPcache' => function_exists('opcache_get_status') && @opcache_get_status(false) ? 'on' : 'off',
                'App role' => $cfg->appRole, 'Base URL' => base_url(),
            ],
            'platform' => [
                'POS app' => $cfg->posUrl, 'API service' => $cfg->apiUrl, 'Storefront' => $cfg->storefrontUrl, 'Marketplace' => $cfg->marketplaceUrl, 'Developers' => $cfg->developersUrl,
                'Site host' => '*.' . $cfg->siteHost, 'Billing currency' => $cfg->platformCurrency, 'Heartbeat timeout' => $cfg->heartbeatTimeout . ' s',
                'Deleted data kept' => $cfg->tenantRetentionDays . ' days', 'Login lock' => $cfg->maxFailedLogins . ' failures → ' . $cfg->lockMinutes . ' min', 'Lookup cache' => $cfg->lookupCacheTtl . ' s',
            ],
        ];
    }
}
