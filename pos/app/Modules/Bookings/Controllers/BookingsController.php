<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Bookings\Services\BookingDesk;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class BookingsController extends BackOfficeController
{
    public function index(): string|ResponseInterface
    {
        $desk = BookingDesk::make();
        $desk->assertEnabled();
        $filter = in_array($f = $this->getStr('f', 10), ['today', 'pending', 'all'], true) ? $f : 'upcoming';
        $kinds = BookingDesk::KINDS;
        $canAct = $this->s()->can('pos.sell');
        $table = DataTable::make('bk', $this->request)->from($desk->listQuery($filter))->keep(['f' => $filter === 'upcoming' ? '' : $filter])
            ->columns([
                Column::make('at', 'When')->nowrap()->sortBy('bk.starts_at')->cell(static fn (array $r): string => $r['starts_at'] ? '<b>' . esc(time_str($r['starts_at'])) . '</b><br><small class="muted">' . esc(date_str($r['starts_at'])) . '</small>' : '<span class="muted">No time</span>')
                    ->csv(static fn (array $r): string => (string) $r['starts_at']),
                Column::make('cust', 'Customer')->sortBy('bk.customer_name')->searchIn('bk.customer_name', 'bk.customer_phone', 'bk.reference', 'bk.staff_name')
                    ->cell(static fn (array $r): string => '<b>' . esc($r['customer_name']) . '</b><br><a class="muted" href="tel:' . esc($r['customer_phone'], 'attr') . '">' . esc($r['customer_phone']) . '</a> · <small class="muted">' . esc($r['reference']) . '</small>')
                    ->csv(static fn (array $r): string => $r['customer_name'] . ' ' . $r['customer_phone']),
                Column::make('what', 'Details')->cell(static function (array $r) use ($kinds): string {
                    $d = json_decode((string) ($r['details'] ?? '{}'), true) ?: [];
                    $bits = array_filter([$r['service'] ? esc($r['service']) : '', $r['party_size'] ? (int) $r['party_size'] . ' people' : '', isset($d['address']) ? icon('pin', 'sm') . ' ' . esc((string) $d['address']) : '',
                        isset($d['brief']) ? esc((string) $d['brief']) : '', $r['note'] ? icon('edit', 'sm') . ' ' . esc($r['note']) : '', $r['staff_name'] ? icon('staff', 'sm') . ' ' . esc($r['staff_name']) : '']);

                    return '<span class="tag">' . icon($kinds[$r['kind']][1] ?? 'calendar', 'sm') . esc($kinds[$r['kind']][0] ?? $r['kind']) . '</span><br>' . (implode('<br>', $bits) ?: '<span class="muted">No details</span>');
                })->noCsv(),
                Column::make('src', 'From')->sortBy('bk.source')->cell(static fn (array $r): string => $r['source'] === 'pos' ? tag_html('Phone / walk-in') : tag_html(ucfirst($r['source']), 'primary'))->csv(static fn (array $r): string => (string) $r['source']),
                Column::make('status', 'Status')->sortBy('bk.status')->cell(static fn (array $r): string => status_tag($r['status']))->csv(static fn (array $r): string => (string) $r['status']),
                Column::make('act', '')->cls('num nw')->noCsv()->cell(static function (array $r) use ($canAct): string {
                    if (! $canAct || ! in_array($r['status'], ['pending', 'confirmed'], true)) {
                        return $r['order_id'] ? '<a class="link" href="' . esc(site_url('orders/' . $r['order_id']), 'attr') . '">Sale</a>' : '';
                    }
                    $f = static fn (string $st, string $label, string $cls, string $ic = ''): string => '<form class="inline" method="post" action="' . esc(site_url('bookings/' . $r['id'] . '/status'), 'attr') . '"' . ($st === 'cancelled' ? ' data-confirm="Cancel this booking?"' : '') . '>' . csrf_field()
                        . '<input type="hidden" name="status" value="' . $st . '"><button class="btn sm ' . $cls . '" type="submit" title="' . esc($label, 'attr') . '">' . ($ic !== '' ? icon($ic, 'sm') : '') . esc($label) . '</button></form> ';

                    return ($r['status'] === 'pending' ? $f('confirmed', 'Confirm', 'primary', 'check') : '')
                        . '<a class="btn sm" href="' . esc(site_url('sell?booking=' . $r['id']), 'attr') . '">' . icon('pos', 'sm') . 'Start sale</a> '
                        . $f('done', 'Done', '') . $f('cancelled', '', 'danger', 'x');
                }),
            ])->defaultSort('at', $filter === 'all' ? 'desc' : 'asc')->placeholder('Customer, phone, reference, staff…')->unit('bookings')->exportable('bookings')
            ->emptyHtml('<div class="empty"><span class="empty-ic">' . icon('calendar') . '</span><b>No bookings here</b><span>' . ($this->s()->moduleOn('website') ? 'Customers can book from your website.' : 'Add bookings by phone with “New booking”.') . '</span></div>');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Bookings\Views\index', [
            'title' => 'Bookings', 'table' => $table->render(), 'filter' => $filter, 'counts' => $desk->counts(), 'kind' => $desk->kind(), 'kinds' => $kinds,
            'services' => $desk->services(), 'staff' => $desk->staff(), 'canAct' => $canAct,
        ]);
    }

    /** Free slots for the booking form (JSON). */
    public function slots(): ResponseInterface
    {
        BookingDesk::make()->assertEnabled();
        $slots = service('bookings')->slots($this->getStr('date', 10), $this->getInt('product_id') ?: null, $this->getStr('staff', 100) ?: null);

        return $this->json($slots);
    }

    public function store(): RedirectResponse
    {
        $row = BookingDesk::make()->create((array) $this->request->getPost());

        return $this->done('Booking ' . $row['reference'] . ' saved.', site_url('bookings'));
    }

    public function status(int $id): RedirectResponse
    {
        $st = (string) $this->request->getPost('status');
        BookingDesk::make()->setStatus($id, $st);

        return $this->done(match ($st) {
            'confirmed' => 'Confirmed — let the customer know by SMS or call.',
            'cancelled' => 'Booking cancelled.',
            default => 'Booking updated.',
        });
    }
}
