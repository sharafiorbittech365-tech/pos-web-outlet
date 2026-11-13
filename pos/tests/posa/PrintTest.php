<?php

declare(strict_types=1);

use App\Modules\Orders\Services\PrintSettings;
use App\Modules\Sales\Services\TillService;
use Tests\Support\PosA\TestCase;

/**
 * Paper size and copies per document (Setup › Invoice › Printing) and the print endpoint.
 *
 * @internal
 */
final class PrintTest extends TestCase
{
    private function sale(): array
    {
        $p = $this->product();

        return (new TillService())->checkout(['lines' => [['product_id' => (int) $p['id'], 'qty' => 1]]], [['method' => 'cash', 'amount' => 99999]], 'tok-print-' . bin2hex(random_bytes(4)))['order'];
    }

    private function setPrint(array $print, ?string $legacy = null): void
    {
        $db = db_connect();
        $s = json_decode((string) $db->table('businesses')->select('settings')->where('id', $this->fx['business_id'])->get()->getRow('settings'), true) ?: [];
        if ($print === []) {
            unset($s['print']);
        } else {
            $s['print'] = $print;
        }
        if ($legacy !== null) {
            $s['printFormat'] = $legacy;
        }
        $db->table('businesses')->where('id', $this->fx['business_id'])->update(['settings' => json_encode($s)]);
        \App\Libraries\PosSession::forgetBusiness($this->fx['business_id']);
    }

    public function testLegacyInvoiceDesignStillDecides(): void
    {
        $this->assertSame(['size' => '80', 'copies' => 1], PrintSettings::for(['settings' => ['printFormat' => 'thermal']], 'receipt'));
        $this->assertSame('a5', PrintSettings::for(['settings' => ['printFormat' => 'a5']], 'receipt')['size']);
        $this->assertSame('a4', PrintSettings::for(['settings' => ['printFormat' => 'thermal']], 'invoice')['size']);
        $this->assertSame('80', PrintSettings::for(['settings' => []], 'kot')['size']);
        // sizes a document cannot use fall back to its first size; copies are clamped
        $this->assertSame(['size' => '80', 'copies' => PrintSettings::MAX_COPIES], PrintSettings::for(['settings' => ['print' => ['kot' => ['size' => 'a4', 'copies' => 99]]]], 'kot'));
    }

    public function testPrintFollowsTheDocumentSettings(): void
    {
        $o = $this->sale();
        $this->setPrint(['receipt' => ['size' => '58', 'copies' => 1], 'invoice' => ['size' => 'a5', 'copies' => 2]]);

        $r = $this->getPage('orders/' . $o['id'] . '/print?doc=receipt&fragment=1');
        $r->assertOK();
        $this->assertSame('receipt', $r->response()->getHeaderLine('X-Print-Kind'));
        $this->assertSame('58', $r->response()->getHeaderLine('X-Print-Size'));
        $this->assertStringContainsString('receipt w58', (string) $r->getBody());

        $r = $this->getPage('orders/' . $o['id'] . '/print?doc=invoice&fragment=1');
        $this->assertSame('a5', $r->response()->getHeaderLine('X-Print-Kind'));
        $this->assertSame(2, substr_count((string) $r->getBody(), 'class="print-copy"'));
        $this->assertStringContainsString('Copy 2 of 2', (string) $r->getBody());

        // the full print page sets the A5 page size
        $page = (string) $this->getPage('orders/' . $o['id'] . '/print?doc=invoice')->getBody();
        $this->assertStringContainsString('size:A5', $page);

        // older links (size / mode) keep working, and ?size overrides the setting
        $r = $this->getPage('orders/' . $o['id'] . '/print?size=a4&fragment=1');
        $this->assertSame('a4', $r->response()->getHeaderLine('X-Print-Kind'));
        $r = $this->getPage('orders/' . $o['id'] . '/print?mode=kot&fragment=1');
        $this->assertStringContainsString('kot', (string) $r->getBody());
    }

    public function testSetupSavesPrinting(): void
    {
        $this->setPrint([], 'thermal');
        $prints = PrintSettings::fromForm(['print_receipt_size' => 'a4', 'print_receipt_copies' => '2', 'print_kot_size' => 'a4'], ['settings' => ['printFormat' => 'thermal']]);
        $this->assertSame(['size' => 'a4', 'copies' => 2], $prints['receipt']);
        $this->assertSame('80', $prints['kot']['size'], 'a kitchen ticket cannot be A4');
        $this->assertSame('a4', $prints['invoice']['size']);
    }
}
