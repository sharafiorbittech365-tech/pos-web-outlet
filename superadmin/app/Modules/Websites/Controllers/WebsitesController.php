<?php

declare(strict_types=1);

namespace App\Modules\Websites\Controllers;

use App\Controllers\BaseController;
use App\Modules\Websites\Services\WebsiteDirectory;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class WebsitesController extends BaseController
{
    public function index(): string|ResponseInterface
    {
        $mode = $this->pick('mode', WebsiteDirectory::FILTERS) ?: 'all';
        $canManage = service('adminSession')->can('tenants.manage');
        $storefront = rtrim(config(\OmniPOS\Core\Config\OmniPOS::class)->storefrontUrl, '/');
        $table = DataTable::make('sites', $this->request)
            ->from(WebsiteDirectory::listQuery(['mode' => $mode]))
            ->keep(['mode' => $mode === 'all' ? '' : $mode])
            ->columns([
                Column::make('store', 'Store')->sortBy('b.name')->searchIn('b.name', 's.slug')
                    ->cell(static fn (array $r): string => '<b>' . esc($r['store_name']) . '</b><br><small class="muted">' . esc($r['theme']) . ' theme' . ($r['published_at'] ? ' · since ' . esc(date_str($r['published_at'])) : '') . '</small>')
                    ->csv(static fn (array $r): string => (string) $r['store_name']),
                Column::make('tenant', 'Tenant')->sortBy('t.name')->searchIn('t.name')
                    ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('tenants/' . $r['tenant_id']), 'attr') . '">' . esc($r['tenant_name']) . '</a>')
                    ->csv(static fn (array $r): string => (string) $r['tenant_name']),
                Column::make('mode', 'Mode')->sortBy('s.mode')
                    ->cell(static fn (array $r): string => tag_html(['storefront' => 'Storefront', 'api' => 'API-only', 'off' => 'Off'][$r['mode']] ?? (string) $r['mode'], $r['mode'] === 'storefront' ? 'ok' : ($r['mode'] === 'api' ? 'primary' : ''))),
                Column::make('host', 'Address')->sortBy('s.slug')->cell(static fn (array $r): string => '<code>' . esc(WebsiteDirectory::address($r)) . '</code>')
                    ->csv(static fn (array $r): string => WebsiteDirectory::address($r)),
                Column::make('domains', 'Domains & SSL')
                    ->cell(static function (array $r) use ($canManage): string {
                        $out = '';
                        foreach (WebsiteDirectory::domains($r['domains']) as $d) {
                            $state = $d['blocked'] ? tag_html('blocked', 'danger') : (! $d['enabled'] ? tag_html('disabled by owner') : tag_html($d['status'] === 'active' ? 'live' : $d['status'], $d['status'] === 'active' ? 'ok' : ($d['status'] === 'failed' ? 'danger' : 'warn')));
                            $out .= '<div class="dline">' . $state . ' ' . esc($d['host']) . ' <small class="muted">' . ($d['hosting'] === 'external' ? 'own server' : 'platform') . '</small>'
                                . ($d['ssl'] === 'active' ? ' <small class="muted">' . icon('lock', 'sm') . ' till ' . esc(date_str($d['expires'] ?: null)) . '</small>' : ($d['ssl'] !== 'none' ? ' <small class="muted">SSL ' . esc($d['ssl']) . '</small>' : ''))
                                . ($canManage ? ' <form class="inline" method="post" action="' . esc(site_url('websites/domains/' . $d['id'] . '/recheck'), 'attr') . '">' . csrf_field()
                                    . '<button class="linkbtn" type="submit" title="Re-check DNS and SSL">' . icon('refresh', 'sm') . 'Re-check</button></form>'
                                    . ' <form class="inline" method="post" action="' . esc(site_url('websites/domains/' . $d['id'] . '/block'), 'attr') . '"' . ($d['blocked'] ? '' : ' data-confirm="Block ' . esc($d['host'], 'attr') . '? It stops serving the store immediately and the owner cannot re-enable it."') . '>' . csrf_field()
                                    . '<input type="hidden" name="blocked" value="' . ($d['blocked'] ? '0' : '1') . '">' . ($d['blocked'] ? '' : '<input class="input xs" name="reason" maxlength="160" placeholder="Reason" required>')
                                    . '<button class="linkbtn" type="submit">' . icon($d['blocked'] ? 'check' : 'lock', 'sm') . ($d['blocked'] ? 'Unblock' : 'Block') . '</button></form>' : '') . '</div>';
                        }

                        return $out !== '' ? $out : '<span class="muted">Free subdomain</span>';
                    })
                    ->csv(static fn (array $r): string => implode('; ', array_map(static fn (array $d): string => $d['host'] . ' (' . $d['status'] . ', SSL ' . $d['ssl'] . ')', WebsiteDirectory::domains($r['domains'])))),
                Column::make('market', 'Marketplace')->sortBy('s.marketplace_listed')
                    ->cell(static fn (array $r): string => (int) $r['marketplace_listed'] ? tag_html('Listed', 'ok') : '<span class="muted">—</span>')
                    ->csv(static fn (array $r): string => (int) $r['marketplace_listed'] ? 'listed' : ''),
                Column::make('act', '')->num()->nowrap()->noCsv()
                    ->cell(static fn (array $r): string => $r['mode'] === 'storefront' ? '<a class="btn sm" href="' . esc($storefront . '/?site=' . rawurlencode((string) $r['slug']), 'attr') . '" target="_blank" rel="noopener">' . icon('external', 'sm') . 'Visit</a>' : ''),
            ])
            ->defaultSort('store')
            ->placeholder('Store, slug or tenant…')
            ->unit('sites')
            ->exportable('websites');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Websites\Views\index', ['title' => 'Websites & domains', 'table' => $table->render(), 'mode' => $mode, 'stats' => WebsiteDirectory::stats()]);
    }

    /** Platform authorisation: block / unblock a domain (the owner cannot lift a block). */
    public function block(int $id): RedirectResponse
    {
        $blocked = (string) $this->request->getPost('blocked') === '1';
        $reason = trim((string) $this->request->getPost('reason'));
        if ($blocked && $reason === '') {
            throw new \OmniPOS\Core\Exceptions\ValidationException('Give a reason for the block.', ['reason' => 'Required.']);
        }
        $d = db_connect()->table('site_domains d')->select('d.host, s.tenant_id')->join('sites s', 's.id = d.site_id')->where('d.id', $id)->get()->getRowArray()
            ?? throw new \OmniPOS\Core\Exceptions\NotFoundException('Domain not found.');
        service('domains')->setBlocked($id, $blocked, $reason, $this->actor());
        service('audit')->platform($blocked ? 'Domain blocked' : 'Domain unblocked', $d['host'] . ($blocked ? ' · ' . $reason : ''), (int) $d['tenant_id'], $this->actor());

        return $this->done($d['host'] . ($blocked ? ' blocked — it no longer serves the store.' : ' unblocked.'));
    }

    public function recheck(int $id): RedirectResponse
    {
        $r = WebsiteDirectory::recheck($id, $this->actor());

        return $this->done($r['host'] . ': DNS ' . ($r['dns'] ? 'verified' : 'not verified yet') . ' · ' . $r['after'] . ' · SSL ' . $r['ssl'] . '.');
    }
}
