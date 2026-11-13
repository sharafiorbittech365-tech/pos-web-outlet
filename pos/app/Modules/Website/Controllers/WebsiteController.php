<?php

declare(strict_types=1);

namespace App\Modules\Website\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Website\Services\WebsiteService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Config\OmniPOS;
use OmniPOS\Core\Services\ApiKeyService;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class WebsiteController extends BackOfficeController
{
    private const TABS = ['overview', 'storefront', 'pages', 'domains', 'selfhosted', 'api', 'webhooks', 'sync'];

    private function to(string $tab): string
    {
        return site_url('website') . ($tab === 'overview' ? '' : '?tab=' . $tab);
    }

    public function index(): string|ResponseInterface
    {
        $svc = new WebsiteService();
        $site = $svc->site();
        $tab = in_array($t = $this->getStr('tab', 12), self::TABS, true) ? $t : 'overview';
        $cur = $this->cur();
        $data = ['title' => 'Website', 'tab' => $tab, 'site' => $site, 'host' => $svc->host($site), 'preview' => $svc->previewUrl($site), 'siteHost' => config(OmniPOS::class)->siteHost,
            'apiUrl' => rtrim(config(OmniPOS::class)->apiUrl, '/') . '/v1', 'developersUrl' => config(OmniPOS::class)->developersUrl, 'devapi' => $this->s()->moduleOn('devapi'),
            'marketplace' => $this->s()->moduleOn('marketplace'), 'cur' => $cur, 'newKey' => session()->getFlashdata('new_key'), 'newSecret' => session()->getFlashdata('new_secret')];

        if ($tab === 'overview') {
            $data['stats'] = $svc->overview($site);
            $orders = DataTable::make('wo', $this->request)->from($svc->webOrdersQuery())->keep([])->noSearch()
                ->columns([
                    Column::make('order', 'Invoice')->sortBy('o.id')->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('orders/' . $r['id']), 'attr') . '"><b>' . esc((string) ($r['invoice_no'] ?: $r['doc_no'])) . '</b></a><br><small class="muted">' . ($r['invoice_no'] ? 'Sale ' . esc($r['doc_no']) . ' · ' : '') . esc((string) $r['web_ref']) . ' · ' . esc(datetime_str($r['created_at'])) . '</small>'),
                    Column::make('cust', 'Customer')->cell(static fn (array $r): string => esc($r['customer_name'])),
                    Column::make('ch', 'Channel')->cell(static fn (array $r): string => tag_html(\App\Modules\Orders\Services\OrderQueries::sourceLabel($r), 'primary')),
                    Column::make('ship', 'Delivery')->cell(static fn (array $r): string => esc((string) $r['order_type']) . ($r['ship_address'] ? '<br><small class="muted">' . esc($r['ship_address']) . '</small>' : '')),
                    Column::make('total', 'Total')->num()->sortBy('o.total')->cell(static fn (array $r): string => '<b>' . esc(money($r['total'], $cur)) . '</b>'),
                    Column::make('status', 'Status')->cell(static fn (array $r): string => status_tag($r['status'])),
                ])->defaultSort('order', 'desc')->perPage(10)->unit('orders')->compact()->emptyHtml('<span class="muted">No website orders yet.</span>');
            $data['orders'] = $orders->render();
        } elseif ($tab === 'pages') {
            $data['pages'] = $svc->pages();
            $data['editPage'] = ($e = $this->getInt('edit')) > 0 ? $svc->page($e) : null;
            $data['newPage'] = $this->getInt('new') > 0;
            $biz = service('posSession')->business();
            $data['pageTemplates'] = \OmniPOS\Core\Services\SitePageTemplates::all((string) ($biz['name'] ?? 'Our shop'), (string) ($biz['currency_code'] ?? 'BDT'));
            $data['pageHtml'] = \OmniPOS\Core\Services\SitePages::render((string) (old('body') ?? ($data['editPage']['body'] ?? '')));
        } elseif ($tab === 'domains') {
            $data['domains'] = $svc->domains((int) $site['id']);
        } elseif ($tab === 'selfhosted') {
            $data['clients'] = $svc->clients((int) $site['id']);
            $data['domains'] = $svc->domains((int) $site['id']);
            $data['newClient'] = session()->getFlashdata('new_client');
            $this->response->setHeader('Cache-Control', 'no-store');
        } elseif ($tab === 'api') {
            $keys = DataTable::make('keys', $this->request)->from($svc->keysQuery((int) $site['id']))->keep(['tab' => 'api'])
                ->columns([
                    Column::make('name', 'Name')->sortBy('k.name')->searchIn('k.name', 'k.prefix')->cell(static fn (array $r): string => '<b>' . esc($r['name']) . '</b><br>' . ($r['key_type'] === 'secret' ? tag_html('Secret · server only', 'danger') : tag_html('Publishable · browser safe', 'primary')) . ($r['is_storefront'] ? ' ' . tag_html('Storefront') : '')),
                    Column::make('key', 'Key')->cell(static fn (array $r): string => '<code>' . esc($r['prefix']) . '••••' . esc($r['last4']) . '</code>'),
                    Column::make('scopes', 'Scopes')->cell(static function (array $r): string {
                        $s = json_decode((string) $r['scopes'], true) ?: [];

                        return '<div class="scopes">' . implode('', array_map(static fn (string $x): string => '<span>' . esc($x) . '</span>', array_slice($s, 0, 4))) . (count($s) > 4 ? '<span>+' . (count($s) - 4) . '</span>' : '') . '</div>';
                    }),
                    Column::make('created', 'Created')->nowrap()->sortBy('k.created_at')->cell(static fn (array $r): string => esc(date_str($r['created_at'])) . ($r['user_name'] ? '<br><small class="muted">' . esc($r['user_name']) . '</small>' : '')),
                    Column::make('used', 'Last used')->nowrap()->sortBy('k.last_used_at')->cell(static fn (array $r): string => $r['last_used_at'] ? esc(datetime_str($r['last_used_at'])) : '<span class="muted">Never</span>'),
                    Column::make('act', '')->cls('num')->cell(static fn (array $r): string => $r['revoked_at'] !== null ? tag_html('Revoked') : '<form class="inline" method="post" action="' . esc(site_url('website/keys/' . $r['id'] . '/revoke'), 'attr') . '" data-confirm="Revoke ' . esc($r['name'], 'attr') . '? Apps using it stop working immediately.">' . csrf_field() . '<button class="btn sm danger" type="submit">Revoke</button></form>'),
                ])->defaultSort('created', 'desc')->placeholder('Key name or prefix…')->unit('keys')
                ->rowAttr(static fn (array $r): string => $r['revoked_at'] !== null ? 'class="revoked"' : '')
                ->emptyHtml('<span class="muted">No keys yet. Create a publishable key for your website and a secret key for your server.</span>');
            $data['keys'] = $keys->render();
            $data['origins'] = $svc->origins((int) $site['id']);
            $data['scopes'] = ApiKeyService::SCOPES;
        } elseif ($tab === 'webhooks') {
            $data['hooks'] = $svc->webhooks((int) $site['id']);
            $data['events'] = WebsiteService::EVENTS;
            $del = DataTable::make('whd', $this->request)->from($svc->deliveriesQuery((int) $site['id']))->keep(['tab' => 'webhooks'])->noSearch()
                ->columns([
                    Column::make('at', 'When')->nowrap()->sortBy('d.created_at')->cell(static fn (array $r): string => esc(datetime_str($r['created_at']))),
                    Column::make('event', 'Event')->sortBy('d.event')->cell(static fn (array $r): string => '<code>' . esc($r['event']) . '</code>'),
                    Column::make('url', 'Endpoint')->cell(static fn (array $r): string => '<small class="muted">' . esc($r['url']) . '</small>'),
                    Column::make('resp', 'Response')->cell(static fn (array $r): string => $r['status_code'] === null ? tag_html($r['delivered_at'] ? 'Sent' : 'Queued', 'warn')
                        : tag_html($r['status_code'] . ((int) $r['status_code'] < 300 ? ' OK' : ' Error · retrying'), (int) $r['status_code'] < 300 ? 'ok' : 'danger')),
                    Column::make('ms', 'Time')->num()->cell(static fn (array $r): string => $r['duration_ms'] !== null ? (int) $r['duration_ms'] . ' ms' : '—'),
                    Column::make('tries', 'Attempts')->num()->cell(static fn (array $r): string => (string) (int) $r['attempts']),
                ])->defaultSort('at', 'desc')->perPage(10)->unit('deliveries')->compact()->emptyHtml('<span class="muted">Nothing sent yet.</span>');
            $data['deliveries'] = $del->render();
        }

        return $this->page('App\Modules\Website\Views\index', $data);
    }

    public function mode(): RedirectResponse
    {
        (new WebsiteService())->setMode((string) $this->request->getPost('mode'));

        return $this->done('Website mode saved.', $this->to('overview'));
    }

    public function storefront(): RedirectResponse
    {
        (new WebsiteService())->saveStorefront((array) $this->request->getPost());

        return $this->done('Saved & published — changes go live in a few seconds.', $this->to('storefront'));
    }

    public function productPage(): RedirectResponse
    {
        (new WebsiteService())->saveProductPage((array) $this->request->getPost());

        return $this->done('Product page saved — live in a few seconds.', $this->to('storefront'));
    }

    public function savePage(?int $id = null): RedirectResponse
    {
        $saved = (new WebsiteService())->savePage($id, (array) $this->request->getPost());

        return $this->done('Page saved — live in a few seconds.', site_url('website') . '?tab=pages&edit=' . $saved);
    }

    public function deletePage(int $id): RedirectResponse
    {
        (new WebsiteService())->deletePage($id);

        return $this->done('Page deleted.', $this->to('pages'));
    }

    public function slug(): RedirectResponse
    {
        (new WebsiteService())->saveSlug((string) $this->request->getPost('slug'));

        return $this->done('Address saved.', $this->to('domains'));
    }

    public function cache(): RedirectResponse
    {
        (new WebsiteService())->saveCache((array) $this->request->getPost());

        return $this->done('Cache settings saved.', $this->to('sync'));
    }

    public function addDomain(): RedirectResponse
    {
        (new WebsiteService())->addDomain((string) $this->request->getPost('host'));

        return $this->done('Domain added — add the DNS records below, then press Check DNS.', $this->to('domains'));
    }

    public function verifyDomain(int $id): RedirectResponse
    {
        $ok = (new WebsiteService())->verifyDomain($id);

        return $ok ? $this->done('Domain verified — SSL is active.', $this->to('domains'))
            : redirect()->to($this->to('domains'))->with('error', 'The TXT record was not found yet. DNS changes can take a few minutes to a few hours.');
    }

    public function primaryDomain(int $id): RedirectResponse
    {
        (new WebsiteService())->setPrimary($id);

        return $this->done('Primary domain saved.', $this->to('domains'));
    }

    public function removeDomain(int $id): RedirectResponse
    {
        (new WebsiteService())->removeDomain($id);

        return $this->done('Domain removed.', $this->to('domains'));
    }

    public function toggleDomain(int $id): RedirectResponse
    {
        $on = (string) $this->request->getPost('enabled') === '1';
        (new WebsiteService())->setDomainEnabled($id, $on);

        return $this->done($on ? 'Domain enabled — it serves your store again.' : 'Domain disabled — it no longer shows your store.', $this->to('domains'));
    }

    public function domainHosting(int $id): RedirectResponse
    {
        (new WebsiteService())->setDomainHosting($id, (string) $this->request->getPost('hosting'));

        return $this->done('Hosting saved.', $this->to('domains'));
    }

    public function createClient(): RedirectResponse
    {
        $out = (new WebsiteService())->createClient((string) $this->request->getPost('client_name'));

        // the secret is shown on the next page only — it is never stored in readable form
        return redirect()->to($this->to('selfhosted'))->with('new_client', $out)->with('success', 'Client created — copy the secret now.');
    }

    public function rotateClient(int $id): RedirectResponse
    {
        $out = (new WebsiteService())->rotateClient($id);

        return redirect()->to($this->to('selfhosted'))->with('new_client', $out)->with('success', 'New secret issued — the old one stopped working. Update CLIENT_SECRET on your server.');
    }

    public function toggleClient(int $id): RedirectResponse
    {
        $on = (new WebsiteService())->toggleClient($id);

        return $this->done($on ? 'Client resumed.' : 'Client paused — the self-hosted site shows "not available" until you resume it.', $this->to('selfhosted'));
    }

    public function revokeClient(int $id): RedirectResponse
    {
        (new WebsiteService())->revokeClient($id);

        return $this->done('Client revoked.', $this->to('selfhosted'));
    }

    public function createKey(): RedirectResponse

    {
        $out = (new WebsiteService())->createKey((string) $this->request->getPost('key_name'), (string) $this->request->getPost('key_type'), array_map('strval', $this->postArray('scopes')));

        // the full key is shown on the next page only — it is never stored
        return redirect()->to($this->to('api'))->with('new_key', ['key' => $out['key'], 'name' => $out['row']['name'], 'type' => $out['row']['key_type']])->with('success', 'Key created — copy it now.');
    }

    public function revokeKey(int $id): RedirectResponse
    {
        (new WebsiteService())->revokeKey($id);

        return $this->done('Key revoked.', $this->to('api'));
    }

    public function addOrigin(): RedirectResponse
    {
        (new WebsiteService())->addOrigin((string) $this->request->getPost('origin'));

        return $this->done('Origin allowed.', $this->to('api'));
    }

    public function removeOrigin(): RedirectResponse
    {
        (new WebsiteService())->removeOrigin((string) $this->request->getPost('origin'));

        return $this->done('Origin removed.', $this->to('api'));
    }

    public function addWebhook(): RedirectResponse
    {
        $secret = (new WebsiteService())->addWebhook((string) $this->request->getPost('url'), array_map('strval', $this->postArray('events')));

        return redirect()->to($this->to('webhooks'))->with('new_secret', $secret)->with('success', 'Endpoint added.');
    }

    public function toggleWebhook(int $id): RedirectResponse
    {
        $on = (new WebsiteService())->toggleWebhook($id);

        return $this->done($on ? 'Endpoint switched on.' : 'Endpoint paused.', $this->to('webhooks'));
    }

    public function testWebhook(int $id): RedirectResponse
    {
        (new WebsiteService())->testWebhook($id);

        return $this->done('Test event queued.', $this->to('webhooks'));
    }

    public function deleteWebhook(int $id): RedirectResponse
    {
        (new WebsiteService())->deleteWebhook($id);

        return $this->done('Endpoint removed.', $this->to('webhooks'));
    }
}
