<?php

declare(strict_types=1);

namespace App\Modules\Multisite\Services;

use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Config\OmniPOS;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\MultisiteKeys;
use OmniPOS\Core\Services\SiteAddresses;

/**
 * POS › Website › Multi-outlet website: the account-wide website that shows the products of the
 * outlets the owner picks (apps/multistore). Settings live in multisites / multisite_outlets; the
 * logo, colours and theme come from the chosen home outlet's Website settings, and every order still
 * goes into one outlet's normal online-order flow.
 *
 * Account-wide, so only the account owner (users.is_owner) may change it; the route also needs
 * module:multisite and can:settings.manage.
 */
final class MultisiteAdmin
{
    private function __construct(private readonly int $tenantId)
    {
    }

    public static function forCurrent(): self
    {
        $u = service('posSession')->user();
        if ($u === null || empty($u['is_owner'])) {
            throw new PermissionDeniedException('Only the account owner can manage the multi-outlet website.');
        }

        return new self(service('tenant')->tenantId());
    }

    private function db(): BaseConnection
    {
        return db_connect();
    }

    /** @return array<string, mixed> the tenant's website row (created, switched off, on first visit) */
    public function website(): array
    {
        $row = $this->db()->table('multisites')->where('tenant_id', $this->tenantId)->get()->getRowArray();
        if ($row !== null) {
            return $row;
        }
        $code = (string) ($this->db()->table('tenants')->select('name')->where('id', $this->tenantId)->get()->getRow('name') ?? 'stores');
        $now = date('Y-m-d H:i:s');
        $this->db()->table('multisites')->insert(['tenant_id' => $this->tenantId, 'home_business_id' => service('tenant')->businessId(),
            'slug' => $this->freeSlug($code), 'is_enabled' => 0, 'created_at' => $now, 'updated_at' => $now]);

        return $this->db()->table('multisites')->where('tenant_id', $this->tenantId)->get()->getRowArray() ?? throw new NotFoundException('Website not found.');
    }

    private function freeSlug(string $base): string
    {
        // the tenant name is usually its first outlet's address too: prefer "<name>-stores" over "<name>-2"
        $base = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($base)), '-') ?: 'stores', 0, 40);
        if (! SiteAddresses::valid($base) || ! SiteAddresses::isFree($base)) {
            $base = substr($base, 0, 33) . '-stores';
        }

        return SiteAddresses::free($base, 'stores', '-stores');
    }

    /** Storefronts and multi-outlet websites share <slug>.<siteHost>: a slug belongs to one website only. */
    private function slugFree(string $slug, int $ownId): bool
    {
        return SiteAddresses::isFree($slug, 0, $ownId);
    }

    /**
     * Every active outlet of the account with its website flags.
     *
     * @return list<array{id: int, name: string, area: ?string, city: ?string, type_name: string, on: bool, sort: int, products: int}>
     */
    public function outlets(): array
    {
        $ms = $this->website();
        $db = $this->db();
        $rows = $db->table('businesses b')->select('b.id, b.name, b.area, b.city, bt.name AS type_name, mo.is_enabled, mo.sort_order')
            ->join('business_types bt', 'bt.code = b.business_type', 'left')
            ->join('multisite_outlets mo', 'mo.business_id = b.id AND mo.tenant_id = b.tenant_id AND mo.multisite_id = ' . (int) $ms['id'], 'left')
            ->where('b.tenant_id', $this->tenantId)->where('b.is_active', 1)
            ->orderBy('mo.sort_order IS NULL', 'ASC', false)->orderBy('mo.sort_order')->orderBy('b.name')->get()->getResultArray();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $counts = [];
        if ($ids !== []) {
            foreach ($db->table('products')->select('business_id, COUNT(*) AS n')->where('tenant_id', $this->tenantId)->whereIn('business_id', $ids)
                ->where('is_active', 1)->where('is_online', 1)->where('deleted_at', null)->groupBy('business_id')->get()->getResultArray() as $c) {
                $counts[(int) $c['business_id']] = (int) $c['n'];
            }
        }

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'area' => $r['area'], 'city' => $r['city'], 'type_name' => (string) ($r['type_name'] ?? ''),
            'on' => (bool) ($r['is_enabled'] ?? false), 'sort' => (int) ($r['sort_order'] ?? 0), 'products' => $counts[(int) $r['id']] ?? 0,
        ], $rows);
    }

    /** @param array<string, mixed> $in name, tagline, slug, domain, home_business_id, is_enabled */
    public function save(array $in): void
    {
        $ms = $this->website();
        $errors = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        $tagline = mb_substr(trim((string) ($in['tagline'] ?? '')), 0, 200);
        $slug = strtolower(trim((string) ($in['slug'] ?? '')));
        if (! SiteAddresses::valid($slug)) {
            $errors['slug'] = '3–48 letters, digits or dashes; not a reserved word.';
        } elseif (! $this->slugFree($slug, (int) $ms['id'])) {
            $errors['slug'] = 'Already used by another website (also outlet storefronts).';
        }
        $domain = strtolower(trim((string) preg_replace('#^https?://#i', '', trim((string) ($in['domain'] ?? '')))));
        $domain = rtrim(explode('/', $domain)[0], '.');
        if ($domain !== '') {
            if (preg_match('/^(?=.{4,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $domain) !== 1) {
                $errors['domain'] = 'Enter a domain like www.yourstores.com.';
            } elseif (SiteAddresses::isPlatformHost($domain)) {
                $errors['domain'] = 'That is a platform address — use the Address field above, or your own domain here.';
            } elseif (SiteAddresses::domainTaken($domain, (int) $ms['id'])) {
                $errors['domain'] = 'This domain is already connected to another website.';
            }
        }
        $home = (int) ($in['home_business_id'] ?? 0);
        if ($this->db()->table('businesses')->where('id', $home)->where('tenant_id', $this->tenantId)->where('is_active', 1)->countAllResults() === 0) {
            $errors['home_business_id'] = 'Choose one of your outlets.';
        }
        $enabled = ! empty($in['is_enabled']);
        if ($errors !== []) {
            throw new ValidationException('Please check the highlighted fields.', $errors);
        }
        if ($enabled && $this->db()->table('multisite_outlets')->where('tenant_id', $this->tenantId)->where('multisite_id', (int) $ms['id'])->where('is_enabled', 1)->countAllResults() === 0) {
            throw new BusinessRuleException('Choose at least one outlet for the website before publishing it.');
        }
        $this->db()->table('multisites')->where('id', (int) $ms['id'])->where('tenant_id', $this->tenantId)->update([
            'name' => $name !== '' ? $name : null, 'tagline' => $tagline !== '' ? $tagline : null, 'slug' => $slug, 'domain' => $domain !== '' ? $domain : null,
            'home_business_id' => $home, 'is_enabled' => $enabled ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        // the website key (usage, rate limits) belongs to the home outlet's site: move it along
        if ($home !== (int) $ms['home_business_id'] && (int) ($ms['api_key_id'] ?? 0) > 0) {
            $this->db()->table('api_keys')->where('id', (int) $ms['api_key_id'])->where('tenant_id', $this->tenantId)
                ->update(['business_id' => $home, 'site_id' => MultisiteKeys::ensureSite($this->tenantId, $home)]);
        }
        service('audit')->activity('Multi-outlet website saved', ($enabled ? 'published' : 'off') . ' · ' . $slug . ($domain !== '' ? ' · ' . $domain : ''));
    }

    /**
     * Which outlets appear, in which order. Outlets without a website record get one (switched off),
     * so their own checkout settings (delivery charge, payment methods, pickup) apply to their orders.
     *
     * @param list<int|string>                $on   outlet ids shown on the website
     * @param array<int|string, int|string>   $sort outlet id => position
     */
    public function saveOutlets(array $on, array $sort): int
    {
        $ms = $this->website();
        $all = array_map(static fn (array $o): int => $o['id'], $this->outlets());
        $on = array_values(array_intersect($all, array_map('intval', $on)));
        $now = date('Y-m-d H:i:s');
        Tx::run(function ($db) use ($ms, $all, $on, $sort, $now): void {
            foreach ($all as $bid) {
                $row = ['tenant_id' => $this->tenantId, 'business_id' => $bid, 'multisite_id' => (int) $ms['id'], 'is_enabled' => in_array($bid, $on, true) ? 1 : 0,
                    'sort_order' => max(0, min(999, (int) ($sort[$bid] ?? 0))), 'created_at' => $now];
                $db->query('INSERT INTO multisite_outlets (tenant_id, business_id, multisite_id, is_enabled, sort_order, created_at) VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE multisite_id = VALUES(multisite_id), is_enabled = VALUES(is_enabled), sort_order = VALUES(sort_order)', array_values($row));
                if ($row['is_enabled'] === 1) {
                    MultisiteKeys::ensureSite($this->tenantId, $bid);
                }
            }
        });
        if ($on === [] && (int) $ms['is_enabled'] === 1) {
            $this->db()->table('multisites')->where('id', (int) $ms['id'])->update(['is_enabled' => 0, 'updated_at' => $now]);
        }
        service('audit')->activity('Multi-outlet website outlets', count($on) . ' outlet(s) shown');

        return count($on);
    }

    /** @return array{key: string, prefix: string, last4: string} the full key, shown once */
    public function issueKey(): array
    {
        $ms = $this->website();
        $out = (new MultisiteKeys())->issue((int) $ms['id'], service('tenant')->userId());
        service('audit')->activity('Multi-outlet website key issued', $out['prefix'] . '…' . $out['last4']);

        return $out;
    }

    public function revokeKey(): void
    {
        $ms = $this->website();
        if ($ms['api_key_id'] === null) {
            return;
        }
        (new MultisiteKeys())->revoke((int) $ms['id']);
        service('audit')->activity('Multi-outlet website key revoked', '');
    }

    /** @return array{prefix: string, last4: string, created_at: string, last_used_at: ?string, name: string}|null */
    public function key(): ?array
    {
        $ms = $this->website();
        if ($ms['api_key_id'] === null) {
            return null;
        }

        return $this->db()->table('api_keys')->select('prefix, last4, name, created_at, last_used_at')->where('id', (int) $ms['api_key_id'])
            ->where('tenant_id', $this->tenantId)->where('revoked_at', null)->get()->getRowArray();
    }

    /** Where the platform serves the website, and the local preview. */
    public function addresses(array $ms): array
    {
        $base = rtrim((string) env('omnipos.multistoreUrl', 'http://localhost:8087/'), '/');

        return [
            'platform' => \OmniPOS\Ui\Brand::scheme() . '://' . $ms['slug'] . '.' . config(OmniPOS::class)->multisiteHost,
            'preview' => $base . '/?site=' . rawurlencode((string) $ms['slug']),
            'api' => rtrim(config(OmniPOS::class)->apiUrl, '/') . '/',
        ];
    }
}
