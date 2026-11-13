<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Subscription\Services\SubscriptionService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class SubscriptionController extends BackOfficeController
{
    /** platform currency (BDT) for subscription amounts */
    private static function tk(float|int|string|null $n): string
    {
        return money($n, ['symbol' => '৳', 'decimals' => 0]);
    }

    public function index(): string|ResponseInterface
    {
        $svc = SubscriptionService::make();
        $acc = $svc->account();
        $cycle = in_array($c = $this->getStr('cycle', 8), ['monthly', 'yearly'], true) ? $c : (string) $acc['billing_cycle'];
        $table = DataTable::make('inv', $this->request)->from($svc->invoicesQuery())->noSearch()
            ->columns([
                Column::make('no', 'Invoice')->sortBy('i.id')->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('subscription/invoices/' . $r['id']), 'attr') . '"><b>' . esc($r['number']) . '</b></a><br><small class="muted">' . esc(date_str($r['issued_at'])) . '</small>')
                    ->csv(static fn (array $r): string => (string) $r['number']),
                Column::make('period', 'Period')->cell(static fn (array $r): string => esc(date_str($r['period_from'])) . ' – ' . esc(date_str($r['period_to'])))->csv(static fn (array $r): string => $r['period_from'] . ' - ' . $r['period_to']),
                Column::make('plan', 'Plan')->cell(static fn (array $r): string => esc($r['plan']) . ($r['note'] ? '<br><small class="muted">' . esc($r['note']) . '</small>' : ''))->csv(static fn (array $r): string => (string) $r['plan']),
                Column::make('total', 'Amount')->num()->sortBy('i.total')->cell(static fn (array $r): string => '<b>' . esc(self::tk($r['total'])) . '</b>')->csv(static fn (array $r): string => (string) $r['total']),
                Column::make('status', 'Status')->sortBy('i.status')->cell(static fn (array $r): string => ($r['status'] === 'paid' ? tag_html('Paid', 'ok') : ($r['status'] === 'void' ? tag_html('Void') : (strtotime((string) $r['due_at']) < time() ? tag_html('Overdue', 'danger') : tag_html('Due', 'warn'))))
                    . ($r['paid_at'] ? '<br><small class="muted">' . esc((string) $r['method']) . ' · ' . esc(date_str($r['paid_at'])) . '</small>' : ''))->csv(static fn (array $r): string => (string) $r['status']),
                Column::make('act', '')->cls('num nw')->noCsv()->cell(static fn (array $r): string => ($r['status'] === 'open' ? '<a class="btn sm primary" href="' . esc(site_url('subscription/pay?ids=' . $r['id']), 'attr') . '">Pay</a> ' : '')
                    . '<a class="btn sm" href="' . esc(site_url('subscription/invoices/' . $r['id']), 'attr') . '" title="Invoice">' . icon('print', 'sm') . '</a>'),
            ])->defaultSort('no', 'desc')->perPage(10)->unit('invoices')->exportable('subscription-invoices')
            ->emptyHtml('<span class="muted">No invoices yet — you are on the free trial.</span>');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }
        $access = $this->s()->access();

        return $this->page('App\Modules\Subscription\Views\index', [
            'title' => 'Subscription', 'acc' => $acc, 'price' => $svc->price($acc), 'plans' => $svc->plans($acc, $cycle), 'cycle' => $cycle, 'modules' => $svc->modules($acc), 'sel' => $svc->selectionState(),
            'open' => $svc->openInvoices(), 'invoices' => $table->render(), 'usage' => $svc->usage(), 'limits' => ['outlets' => $access->limitOutlets, 'users' => $access->limitUsers],
            'typeName' => service('catalog')->typeName((string) $acc['business_type']), 'methods' => SubscriptionService::METHODS, 'canAddons' => $access->feature('addons') || $acc['status'] === 'trial',
        ]);
    }

    public function payForm(): string
    {
        $svc = SubscriptionService::make();
        $ids = array_values(array_filter(array_map('intval', explode(',', $this->getStr('ids', 200)))));
        $open = array_values(array_filter($svc->openInvoices(), static fn (array $i): bool => $ids === [] || in_array((int) $i['id'], $ids, true)));

        return $this->page('App\Modules\Subscription\Views\pay', ['title' => 'Pay subscription', 'invoices' => $open, 'acc' => $svc->account(), 'methods' => SubscriptionService::METHODS]);
    }

    public function pay(): RedirectResponse
    {
        $ids = array_map('intval', $this->postArray('ids'));
        $sum = SubscriptionService::make()->pay($ids, (string) $this->request->getPost('method'), (string) $this->request->getPost('reference'), (bool) $this->request->getPost('save'));

        return $this->done('Payment of ' . self::tk($sum) . ' received — thank you!', site_url('subscription'));
    }

    public function plan(): RedirectResponse
    {
        $r = SubscriptionService::make()->changePlan((int) $this->request->getPost('plan_id'), (string) $this->request->getPost('cycle'));
        if ($r['invoice'] !== null) {
            return redirect()->to(site_url('subscription/pay?ids=' . $r['invoice']['id']))->with('success', $r['kind'] === 'start' ? 'Pay the first invoice to start ' . $r['plan'] . '.' : 'You are now on ' . $r['plan'] . '. Pay the prorated difference.');
        }

        return $this->done(match ($r['kind']) {
            'downgrade' => $r['plan'] . ' starts at your next renewal.',
            'keep' => 'The scheduled change was cancelled.',
            default => 'You are now on ' . $r['plan'] . '.',
        }, site_url('subscription'));
    }

    public function addModule(string $code): RedirectResponse
    {
        $inv = SubscriptionService::make()->addModule($code);
        if ($inv !== null) {
            return redirect()->to(site_url('subscription/pay?ids=' . $inv['id']))->with('success', 'Module added — pay the prorated amount for the rest of this period.');
        }

        return $this->done('Module added — it is on now.', site_url('subscription#modules'));
    }

    /** modules[code] / features[code] = 1|0 from the selection form (only valid codes, as booleans) */
    private function wanted(): array
    {
        $want = [];
        $read = function (string $key): array {
            $in = $this->request->getMethod() === 'GET' ? $this->request->getGet($key) : $this->request->getPost($key);
            $out = [];
            foreach ((array) ($in ?? []) as $code => $v) {
                if (is_string($code) && preg_match('/^[a-z0-9_.]{1,48}$/', $code)) {
                    $out[$code] = in_array((string) (is_array($v) ? end($v) : $v), ['1', 'on', 'true'], true);
                }
            }

            return $out;
        };
        foreach (['modules', 'features'] as $k) {
            if (($v = $read($k)) !== []) {
                $want[$k] = $v;
            }
        }

        return $want;
    }

    /** GET subscription/quote — live price preview of a selection (nothing is saved). */
    public function quote(): ResponseInterface
    {
        $o = $this->request->getGet('outlets');
        $outlets = is_string($o) && ctype_digit($o) && (int) $o >= 1 ? min(100000, (int) $o) : null;

        return $this->response->setJSON(['data' => SubscriptionService::make()->quote($this->wanted(), $outlets)]);
    }

    /** POST subscription/selection — save the module & feature selection; the server recomputes the price. */
    public function selection(): RedirectResponse
    {
        $r = SubscriptionService::make()->applySelection($this->wanted());
        if ($r['changes'] === []) {
            return $this->done('Nothing changed.', site_url('subscription#modules'));
        }
        $msg = implode(', ', array_column($r['changes'], 'label')) . '. New monthly price ' . self::tk($r['monthly']) . '.';
        if ($r['invoice'] !== null && $r['invoice']['status'] === 'open') {
            return redirect()->to(site_url('subscription/pay?ids=' . $r['invoice']['id']))->with('success', $msg . ' The prorated amount is on invoice ' . $r['invoice']['number'] . '.');
        }

        return $this->done($msg, site_url('subscription#modules'));
    }

    public function removeModule(string $code): RedirectResponse
    {
        SubscriptionService::make()->removeModule($code);

        return $this->done('Module removed — its pages are hidden; data is kept.', site_url('subscription#modules'));
    }

    public function cancel(): RedirectResponse
    {
        SubscriptionService::make()->cancel();

        return $this->done('The subscription ends at the end of this period.', site_url('subscription'));
    }

    public function resume(): RedirectResponse
    {
        SubscriptionService::make()->resume();

        return $this->done('Your subscription continues.', site_url('subscription'));
    }

    public function method(): RedirectResponse
    {
        SubscriptionService::make()->setPayMethod((string) $this->request->getPost('method'), (bool) $this->request->getPost('auto_pay'));

        return $this->done('Payment method saved.', site_url('subscription'));
    }

    public function invoice(int $id): string
    {
        return $this->page('App\Modules\Subscription\Views\invoice', ['title' => 'Invoice', 'i' => SubscriptionService::make()->invoice($id), 'lines' => SubscriptionService::make()->invoiceLines($id), 'acc' => SubscriptionService::make()->account(),
            'platform' => ['name' => (string) service('catalog')->setting('name', \OmniPOS\Ui\Brand::name() . ' Cloud'), 'email' => (string) service('catalog')->setting('supportEmail', ''), 'phone' => (string) service('catalog')->setting('supportPhone', '')]]);
    }
}
