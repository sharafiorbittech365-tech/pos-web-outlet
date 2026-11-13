<?php

declare(strict_types=1);

namespace App\Modules\Sales\Controllers;

use App\Controllers\BaseController;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\OrderDocs;
use App\Modules\Orders\Services\PrintSettings;
use App\Modules\Register\Services\RegisterService;
use App\Modules\Sales\Services\TillCatalog;
use App\Modules\Sales\Services\TillService;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\PermissionDeniedException;

/**
 * POS till. The page is a full-screen client (public/assets/pos/pos.js) that keeps the cart in
 * the browser and asks the server for every price: POST sell/quote on each change, POST
 * sell/checkout to finalize (see TillService).
 */
final class SellController extends BaseController
{
    public function index(): string
    {
        $s = service('posSession');
        $b = $s->business();
        $catalog = new TillCatalog($b);
        $features = (array) ($b['settings']['features'] ?? []);
        $features['seats'] = ! empty($features['seats']) && $s->moduleOn('floor');
        $features['kitchen'] = ! empty($features['kitchen']) && $s->moduleOn('kitchen');
        $u = $s->user();
        $walkIn = db_connect()->table('customers')->select('id, name')->where('business_id', $s->businessId())->where('is_walk_in', 1)->get()->getRowArray();
        $reg = RegisterService::forCurrent()->current();
        $config = [
            'base' => rtrim(site_url('/'), '/') . '/',
            'business' => [
                'id' => (int) $b['id'], 'name' => $b['name'], 'type' => $b['type_name'] ?? '', 'branch' => (string) ($b['settings']['branch'] ?? ''),
                'currency' => $s->currency(), 'code' => (string) $b['currency_code'], 'taxLabel' => (string) $b['tax_label'],
                'rates' => \OmniPOS\Core\Pricing\TaxPolicy::fromBusiness($b)->rates(),
                // every business word (customer, walkIn, invoice … as well as catalog, seat, kitchen, dueDate)
                'labels' => \OmniPOS\Core\Services\BusinessTypeSetup::terms((string) ($b['business_type'] ?? ''), (array) ($b['settings']['labels'] ?? [])),
                'features' => $features, 'payments' => (array) ($b['settings']['payments'] ?? ['cash']),
                'typeCharges' => (object) ($b['settings']['typeCharges'] ?? []), 'serviceStaff' => array_values(array_merge((array) ($b['settings']['serviceStaff'] ?? []), (array) ($b['settings']['staff'] ?? []))),
                'stock' => (bool) $b['stock_enabled'], 'printSize' => OrderDocs::defaultSize($b), 'print' => PrintSettings::all($b),
            ],
            'walkIn' => $walkIn ? ['id' => (int) $walkIn['id'], 'name' => $walkIn['name']] : null,
            'categories' => array_map(static fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'icon' => $c['icon'], 'n' => (int) $c['n']], $catalog->categories()),
            'tables' => $features['seats'] ? array_map(static fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'seats' => (int) $t['seats'], 'rate' => $t['hourly_rate'] !== null ? (float) $t['hourly_rate'] : null], $catalog->tables()) : [],
            'user' => ['id' => (int) $u['id'], 'name' => $u['name'], 'role' => $u['role'], 'color' => $u['color'], 'maxDiscount' => $u['locked'] || $u['is_owner'] ? 100 : (float) $u['max_discount']],
            'can' => [
                'price' => \App\Modules\Sales\Services\PosSettings::allows('pos.price'), 'discount' => \App\Modules\Sales\Services\PosSettings::allows('pos.discount'), 'credit' => \App\Modules\Sales\Services\PosSettings::allows('pos.credit'), 'quotation' => \App\Modules\Sales\Services\PosSettings::allows('pos.quotation'),
                'return' => $s->can('pos.return'), 'orders' => $s->can('orders.view'), 'customers' => $s->can('customers.view') && $s->moduleOn('customers'),
                'addCustomer' => $s->can('pos.sell'), 'register' => $s->can('register.view') && $s->moduleOn('register'), 'expense' => $s->can('expenses.manage') && $s->moduleOn('expenses'),
            ],
            'register' => $reg ? ['id' => (int) $reg['id'], 'opened_at' => $reg['opened_at']] : null,
            'start' => [
                'table' => (string) ($this->request->getGet('table') ?? ''), 'resume' => (int) ($this->request->getGet('resume') ?? 0),
                'pay' => (int) ($this->request->getGet('pay') ?? 0),
            ],
            'demoImg' => asset_url('img/demo.svg'),
        ];

        return view('App\Views\pos_layout', ['title' => 'POS', 'config' => $config, 'content' => view('App\Modules\Sales\Views\till', ['config' => $config])]);
    }

    public function products(): ResponseInterface
    {
        $catalog = new TillCatalog(service('posSession')->business());
        $r = $catalog->products((string) ($this->request->getGet('q') ?? ''), (int) ($this->request->getGet('cat') ?? 0), (string) $this->request->getGet('featured') === '1', (int) ($this->request->getGet('page') ?? 1));

        return $this->json($r['items'], ['more' => $r['more']]);
    }

    public function lookup(): ResponseInterface
    {
        $r = (new TillCatalog(service('posSession')->business()))->lookup((string) ($this->request->getGet('code') ?? ''));

        return $this->json($r);
    }

    public function serials(int $productId): ResponseInterface
    {
        return $this->json((new TillCatalog(service('posSession')->business()))->serials($productId, (string) ($this->request->getGet('q') ?? '')));
    }

    public function customers(): ResponseInterface
    {
        $rows = (new CustomerService())->search((string) ($this->request->getGet('q') ?? ''));

        return $this->json(array_map(static fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'phone' => $c['phone'], 'group' => $c['price_group'],
            'due' => (float) $c['balance_due'], 'exchange' => (float) ($c['exchange_balance'] ?? 0), 'limit' => (float) $c['credit_limit'], 'walkIn' => (bool) $c['is_walk_in']], $rows));
    }

    public function addCustomer(): ResponseInterface
    {
        $in = $this->input();
        $c = (new CustomerService())->create(['name' => (string) ($in['name'] ?? ''), 'phone' => (string) ($in['phone'] ?? ''), 'address' => (string) ($in['address'] ?? ''),
            'credit_limit' => service('posSession')->can('customers.manage') ? (float) ($in['credit_limit'] ?? 0) : 0]);

        return $this->json(['id' => (int) $c['id'], 'name' => $c['name'], 'phone' => $c['phone'], 'due' => 0, 'limit' => (float) $c['credit_limit'], 'walkIn' => false], [], 201);
    }

    public function quote(): ResponseInterface
    {
        $in = $this->input();

        return $this->json((new TillService())->quote((array) ($in['cart'] ?? [])));
    }

    public function checkout(): ResponseInterface
    {
        $in = $this->input();
        $r = (new TillService())->checkout((array) ($in['cart'] ?? []), (array) ($in['payments'] ?? []), (string) ($in['token'] ?? ''));

        return $this->json($this->receipt($r['order'], $r['change'], $r['tendered']) + ['replay' => $r['replay']]);
    }

    /** Send to the kitchen / table now, pay later. */
    public function open(): ResponseInterface
    {
        $in = $this->input();
        $r = (new TillService())->openOrder((array) ($in['cart'] ?? []), (string) ($in['token'] ?? ''));

        return $this->json($this->receipt($r['order'], 0, 0) + ['replay' => $r['replay']]);
    }

    /** Take payment for an open / due order from the till. */
    public function pay(int $orderId): ResponseInterface
    {
        $in = $this->input();
        $r = (new OrderActions())->pay($orderId, (array) ($in['payments'] ?? []));

        return $this->json($this->receipt($r['order'], $r['change'], $r['tendered']));
    }

    public function hold(): ResponseInterface
    {
        $in = $this->input();
        $id = (new TillService())->hold((array) ($in['cart'] ?? []), (string) ($in['note'] ?? ''), (string) ($in['kind'] ?? 'hold'));

        return $this->json(['id' => $id], [], 201);
    }

    public function parked(): ResponseInterface
    {
        return $this->json((new TillService())->parked());
    }

    public function resume(int $id): ResponseInterface
    {
        return $this->json((new TillService())->resume($id));
    }

    public function deleteHeld(int $id): ResponseInterface
    {
        if (! service('posSession')->can('pos.void') && ! service('posSession')->can('pos.quotation')) {
            throw new PermissionDeniedException('Your role cannot delete suspended sales.');
        }
        (new TillService())->deleteHeld($id);

        return $this->json(['deleted' => $id]);
    }

    /** Receipt payload for the "Payment complete" dialog. */
    private function receipt(array $order, float $change, float $tendered): array
    {
        $s = service('posSession');
        $b = $s->business();
        $order = service('orders')->find((int) $order['id']);
        $d = (new OrderDocs())->build($order, $b);
        $cur = $s->currency();

        return [
            'order' => ['id' => (int) $order['id'], 'doc_no' => $order['doc_no'], 'status' => $order['status'], 'total' => (float) $order['total'],
                'due' => (float) $order['due_total'], 'paid' => (float) $order['paid_total'], 'kitchen' => $order['kitchen_status'] !== 'none'],
            'change' => $change, 'change_text' => money($change, $cur), 'total_text' => money((float) $order['total'], $cur), 'due_text' => money((float) $order['due_total'], $cur),
            'receipt_html' => view('App\Modules\Orders\Views\docs\receipt', ['d' => $d, 'mode' => 'invoice', 'width' => 80, 'tendered' => $tendered, 'change' => $change]),
            'print' => [
                // paper and copies per document come from Setup › Invoice (PrintSettings)
                'receipt' => site_url('orders/' . $order['id'] . '/print?doc=receipt&auto=1'),
                'invoice' => site_url('orders/' . $order['id'] . '/print?doc=invoice&auto=1'),
                'challan' => ! empty($b['settings']['features']['challan']) ? site_url('orders/' . $order['id'] . '/print?doc=challan&auto=1') : null,
                'kot' => $order['kitchen_status'] !== 'none' ? site_url('orders/' . $order['id'] . '/print?doc=kot&auto=1') : null,
            ],
            'url' => site_url('orders/' . $order['id']),
        ];
    }
}
