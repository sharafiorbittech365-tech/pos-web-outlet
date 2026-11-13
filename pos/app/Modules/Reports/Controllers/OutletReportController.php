<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\BackOffice\Support\DateRange;
use App\Modules\Reports\Services\OutletReport;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\PermissionDeniedException;

/** GET reports/outlets — consolidated sales & stock of every outlet the user may report on (outlet filter, CSV). */
final class OutletReportController extends BackOfficeController
{
    public function index(): string|ResponseInterface
    {
        $range = DateRange::fromRequest($this->request, 'month');
        $r = new OutletReport($range->from, $range->to);
        $outlets = $r->outlets();
        $pick = (int) ($this->request->getGet('outlet') ?? 0);
        if ($pick > 0 && ! isset($outlets[$pick])) {
            throw new PermissionDeniedException('You cannot see reports for that outlet.');
        }
        $ids = $pick > 0 ? [$pick] : array_keys($outlets);
        $data = $r->figures($ids);
        $currencies = array_unique(array_map(static fn (int $id): string => $outlets[$id]['currency_symbol'] . '|' . $outlets[$id]['currency_decimals'], $ids));
        $sameCurrency = count($currencies) <= 1;

        if ($this->request->getGet('csv') === '1') {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, ['Outlet', 'Type', 'Currency', 'Invoices', 'Sales', 'Collected', 'New due', 'Returns', 'Expenses', 'Products', 'Stock units', 'Stock value (cost)', 'Low stock items']);
            foreach ($data['rows'] as $x) {
                $o = $outlets[$x['id']];
                fputcsv($out, [$o['name'], $o['type_name'], $o['currency_code'], $x['n'], $x['sales'], $x['collected'], $x['due_new'], $x['returns'], $x['expenses'], $x['products'], $x['units'], round($x['stock_value'], 2), $x['low']]);
            }
            rewind($out);
            $csv = (string) stream_get_contents($out);
            fclose($out);
            service('audit')->activity('Report exported', 'All outlets · ' . $range->label());

            return $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8')
                ->setHeader('Content-Disposition', 'attachment; filename="outlets-' . date('Ymd') . '.csv"')->setBody($csv);
        }

        return $this->page('App\Modules\Reports\Views\outlets', [
            'title' => 'All outlets', 'range' => $range, 'outlets' => $outlets, 'pick' => $pick, 'rows' => $data['rows'], 'total' => $sameCurrency ? $data['total'] : null,
        ]);
    }
}
