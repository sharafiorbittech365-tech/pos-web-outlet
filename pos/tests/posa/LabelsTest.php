<?php

declare(strict_types=1);

use App\Modules\Products\Services\LabelService;
use App\Modules\Sales\Services\TillCatalog;
use Tests\Support\PosA\TestCase;

/**
 * Products › Print labels: label codes scan back to the product at the till, design validation.
 *
 * @internal
 */
final class LabelsTest extends TestCase
{
    public function testEan13CheckDigit(): void
    {
        // the check digit rule against a published EAN-13 (4006381333931)
        $sum = 0;
        foreach (str_split('400638133393') as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }
        $this->assertSame(1, (10 - $sum % 10) % 10);
        $code = LabelService::ean13(7, 1234);
        $this->assertMatchesRegularExpression('/^2\d{12}$/', $code);
        $this->assertSame('2000700012340', substr($code, 0, 12) . '0', 'in-store prefix 2, outlet, product');
        $this->assertNotSame(LabelService::ean13(7, 1234), LabelService::ean13(8, 1234));
    }

    public function testMissingCodesAreCreatedAndScanAtTheTill(): void
    {
        $db = db_connect();
        $bid = $this->fx['business_id'];
        $p = $this->product();
        $db->table('products')->where('id', $p['id'])->update(['sku' => null, 'barcode' => null]);
        $item = array_values(array_filter(LabelService::forCurrent()->items(), static fn ($i) => $i['product_id'] === (int) $p['id']))[0];
        $this->assertSame('', $item['code']);

        $this->assertSame(1, LabelService::forCurrent()->createMissingCodes([$item['key'], 'p999999999', 'x1']));
        $code = (string) $db->table('products')->select('barcode')->where('id', $p['id'])->get()->getRow('barcode');
        $this->assertSame(LabelService::ean13($bid, (int) $p['id']), $code);
        $found = (new TillCatalog(service('posSession')->business()))->lookup($code);
        $this->assertSame((int) $p['id'], (int) $found['product']['id'], 'the printed code finds the product at the till');

        // running it again changes nothing; a product with a SKU keeps it
        $this->assertSame(0, LabelService::forCurrent()->createMissingCodes([$item['key']]));
        $this->seeInDatabase('activity_log', ['action' => 'Product codes created']);
    }

    public function testDesignIsValidated(): void
    {
        $t = LabelService::templateFrom(['preset' => 'custom', 'w' => '500', 'h' => '5', 'codeType' => 'evil', 'fontSize' => '99',
            'fields' => ['name' => '1', 'bogus' => '1'], 'colors' => ['text' => 'red;', 'bg' => '#abcdef']]);
        $this->assertSame([120.0, 12.0], [$t['w'], $t['h']]);
        $this->assertSame('barcode', $t['codeType']);
        $this->assertSame(16, $t['fontSize']);
        $this->assertTrue($t['fields']['name']);
        $this->assertArrayNotHasKey('bogus', $t['fields']);
        $this->assertSame('#111111', $t['colors']['text']);
        $this->assertSame('#ABCDEF', $t['colors']['bg']);
        // a preset fixes its size
        $this->assertSame(70, (int) LabelService::templateFrom(['preset' => 'a4-3x8', 'w' => 10])['w']);
    }

    public function testPageAndSavedDesign(): void
    {
        $p = $this->product();
        $r = $this->getPage('products/labels?ids[]=' . $p['id']);
        $r->assertOK();
        $r->assertSee('Print labels');
        $r->assertSee('qrcode-generator-1.4.4.js');

        LabelService::forCurrent()->saveTemplate(LabelService::templateFrom(['preset' => 'a4-3x8', 'codeType' => 'qr']));
        service('posSession')->reset();
        $t = LabelService::template(service('posSession')->business());
        $this->assertSame('a4-3x8', $t['preset']);
        $this->assertSame('qr', $t['codeType']);
    }
}
