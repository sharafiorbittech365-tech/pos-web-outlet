<?php

declare(strict_types=1);

use App\Modules\BackOffice\Support\DateRange;
use App\Modules\Reports\Services\ReportService;
use Tests\Support\PosB\FeatureTestCase;

/**
 * @internal
 */
final class ReportsTest extends FeatureTestCase
{
    /** @return array{0: int, 1: int, 2: int} inclusive 5%, exclusive 7.5%, exempt product ids */
    private function catalogue(): array
    {
        $db = db_connect();
        $ids = [];
        foreach ([['Phone case', 'default', null, 500], ['Earbuds', 'exclusive', 7.5, 1000], ['Book', 'exempt', null, 300]] as [$name, $mode, $rate, $price]) {
            $db->table('products')->insert(['tenant_id' => $this->fx['tenant_id'], 'business_id' => $this->fx['business_id'], 'category_id' => $this->fx['category_id'], 'name' => $name,
                'price' => $price, 'cost' => $price * 0.6, 'tax_mode' => $mode, 'tax_rate' => $rate, 'stock_type' => 'simple', 'stock_qty' => 100, 'created_at' => date('Y-m-d H:i:s')]);
            $ids[] = (int) $db->insertID();
        }

        return $ids;
    }

    private function sell(array $lines): array
    {
        $this->bind('owner');
        $q = service('orders')->quote(['lines' => $lines]);

        return service('orders')->create(['channel' => 'pos', 'lines' => $lines, 'payments' => [['method' => 'cash', 'amount' => $q['totals']->total]]]);
    }

    public function testVatSummaryEqualsOrderTaxes(): void
    {
        [$a, $b, $c] = $this->catalogue();
        $this->sell([['product_id' => $a, 'qty' => 2], ['product_id' => $b, 'qty' => 1], ['product_id' => $c, 'qty' => 3]]);
        $this->sell([['product_id' => $b, 'qty' => 2]]);
        $range = DateRange::make('today');
        $this->bind('owner');
        $r = new ReportService($range->from, $range->to);

        $db = db_connect();
        $expected = (float) $db->query('SELECT SUM(ot.tax) AS t FROM order_taxes ot JOIN orders o ON o.id = ot.order_id WHERE o.business_id = ?', [$this->fx['business_id']])->getRow('t');
        $vat = $r->vatByRate();
        $this->assertEqualsWithDelta($expected, array_sum(array_column($vat, 'tax')), 0.01);
        $modes = array_column($vat, 'mode');
        $this->assertContains('inclusive', $modes);
        $this->assertContains('exclusive', $modes);
        $this->assertContains('exempt', $modes);
        $sum = $r->summary();
        $this->assertSame(2, $sum['n']);
        $this->assertEqualsWithDelta((float) $db->query('SELECT SUM(tax_total) AS t FROM orders WHERE business_id = ?', [$this->fx['business_id']])->getRow('t'), $sum['tax_kept'], 0.01);

        // VAT register (per invoice) adds up to the same VAT
        $reg = $r->vatRegisterQuery()->get()->getResultArray();
        $this->assertCount(2, $reg);
        $this->assertEqualsWithDelta($expected, array_sum(array_map('floatval', array_column($reg, 'tax'))), 0.02);

        $page = $this->getPage('reports');
        $page->assertOK();
        $page->assertSee('VAT summary');
        $page->assertSee('Earbuds');
        $this->getPage('reports', ['range' => 'custom', 'from' => date('Y-m-d', strtotime('-40 days')), 'to' => date('Y-m-d')])->assertOK();
        $csv = $this->getPage('reports', ['vreg_export' => 'csv']);
        $csv->assertOK();
        $this->assertStringContainsString('Invoice', (string) $csv->getBody());
    }

    public function testVatIsNetOfReturns(): void
    {
        [$a] = $this->catalogue();
        $order = $this->sell([['product_id' => $a, 'qty' => 4]]);
        $this->bind('owner');
        service('orders')->returnItems((int) $order['id'], [['item_id' => (int) $order['items'][0]['id'], 'qty' => 2]], 'cash', 'Test');
        $range = DateRange::make('today');
        $this->bind('owner');
        $vat = (new ReportService($range->from, $range->to))->vatByRate();
        $full = (float) db_connect()->table('order_taxes')->selectSum('tax')->where('order_id', $order['id'])->get()->getRow('tax');
        $this->assertEqualsWithDelta($full / 2, array_sum(array_column($vat, 'tax')), 0.02);
    }
}
