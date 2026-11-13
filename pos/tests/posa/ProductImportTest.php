<?php

declare(strict_types=1);

use App\Modules\Settings\Services\ProductImport;
use App\Modules\Settings\Services\SettingsService;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\OutletProvisioner;
use Tests\Support\PosA\Fixture;
use Tests\Support\PosA\TestCase;

/**
 * Setup › Outlets: a new outlet copies products from an outlet of the same business type.
 *
 * @internal
 */
final class ProductImportTest extends TestCase
{
    protected function businessType(): string
    {
        return 'footwear';
    }

    private function newOutlet(string $type = 'footwear', string $products = 'copy'): int
    {
        return (new SettingsService())->addOutlet(['name' => 'Branch ' . bin2hex(random_bytes(3)), 'type' => $type, 'products' => $products, 'copy_from' => $this->fx['business_id']]);
    }

    public function testNewOutletCopiesChosenProductsWithOpeningStock(): void
    {
        $db = db_connect();
        $src = $this->fx['business_id'];
        $target = $this->newOutlet();
        $this->assertSame(0, $db->table('products')->where('business_id', $target)->countAllResults(), 'copying: no starter products');
        $this->assertSame(0, $db->table('categories')->where('business_id', $target)->countAllResults());

        $simple = $this->product('simple', 0);
        $variant = $db->table('products')->where('business_id', $src)->where('stock_type', 'variant')->orderBy('id')->get()->getRowArray();
        $vars = $db->table('product_variants')->where('product_id', $variant['id'])->orderBy('id')->get()->getResultArray();
        $this->assertGreaterThan(1, count($vars));

        // a picture of the source outlet
        $db->table('site_media')->insert(['tenant_id' => $this->fx['tenant_id'], 'business_id' => $src, 'slot' => 'product', 'mime' => 'image/webp', 'ext' => 'webp', 'size' => 3, 'hash' => 'abcdefabcdefabcd', 'data' => 'abc', 'created_at' => date('Y-m-d H:i:s')]);
        $media = '/media/' . $db->insertID() . '-abcdefabcdefabcd.webp';
        $db->table('product_images')->insert(['tenant_id' => $this->fx['tenant_id'], 'business_id' => $src, 'product_id' => $simple['id'], 'path' => $media, 'thumb_path' => $media, 'sort_order' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        $db->table('products')->where('id', $simple['id'])->update(['image_path' => $media]);

        $svc = new ProductImport();
        $this->assertSame([$src], array_map(static fn (array $o): int => (int) $o['id'], $svc->sources($target)));
        $cat = $svc->catalogue($src, $target);
        $this->assertCount($db->table('products')->where('business_id', $src)->where('deleted_at', null)->countAllResults(), $cat);

        $res = $svc->import($src, $target, [
            $simple['id'] => ['q' => 7],
            $variant['id'] => ['v' => [$vars[0]['id'] => 3, $vars[1]['id'] => 2]],
        ]);
        $this->assertSame(2, $res['imported']);
        $this->assertSame(0, $res['skipped']);
        $this->assertSame(3, $res['stock'], 'one opening line for the simple product, one per stocked option');

        $ns = $db->table('products')->where('business_id', $target)->where('name', $simple['name'])->get()->getRowArray();
        $this->assertSame(7.0, (float) $ns['stock_qty']);
        $this->assertSame([(float) $simple['price'], $simple['sku']], [(float) $ns['price'], $ns['sku']]);
        $this->seeInDatabase('stock_movements', ['business_id' => $target, 'product_id' => $ns['id'], 'reason' => 'opening', 'qty_change' => 7]);
        // its category exists in the target under the same name
        $srcCat = $db->table('categories')->select('name')->where('id', $simple['category_id'])->get()->getRow('name');
        $this->assertSame($srcCat, $db->table('categories')->select('name')->where('id', $ns['category_id'])->where('business_id', $target)->get()->getRow('name'));

        // the picture is the target outlet's own copy
        $this->assertNotSame($media, $ns['image_path']);
        $this->assertMatchesRegularExpression('#^/media/\d+-abcdefabcdefabcd\.webp$#', (string) $ns['image_path']);
        preg_match('#/media/(\d+)-#', (string) $ns['image_path'], $m);
        $this->seeInDatabase('site_media', ['id' => (int) $m[1], 'business_id' => $target]);
        $this->assertSame(1, $db->table('product_images')->where('business_id', $target)->where('product_id', $ns['id'])->countAllResults());

        // size × colour: every option copied, opening stock per option, product stock = their sum
        $nv = $db->table('products')->where('business_id', $target)->where('name', $variant['name'])->get()->getRowArray();
        $this->assertSame(5.0, (float) $nv['stock_qty']);
        $copied = $db->table('product_variants')->where('product_id', $nv['id'])->orderBy('id')->get()->getResultArray();
        $this->assertSame(array_column($vars, 'name'), array_column($copied, 'name'));
        $this->assertSame([3.0, 2.0], [(float) $copied[0]['stock_qty'], (float) $copied[1]['stock_qty']]);
        $this->assertSame((int) $target, (int) $copied[0]['business_id']);

        // the source outlet is untouched
        $this->assertSame((float) $simple['stock_qty'], (float) $db->table('products')->select('stock_qty')->where('id', $simple['id'])->get()->getRow('stock_qty'));

        // importing again adds nothing twice
        $again = $svc->import($src, $target, [$simple['id'] => ['q' => 1]]);
        $this->assertSame([0, 1], [$again['imported'], $again['skipped']]);
        $this->assertSame(2, $db->table('products')->where('business_id', $target)->countAllResults());
        $this->assertTrue(array_values(array_filter($svc->catalogue($src, $target), static fn (array $p): bool => $p['id'] === (int) $simple['id']))[0]['exists']);
        $this->seeInDatabase('activity_log', ['action' => 'Products imported']);
    }

    public function testOnlySameBusinessTypeAndOwnOutlets(): void
    {
        $src = $this->fx['business_id'];
        $svc = new ProductImport();
        $grocery = (new OutletProvisioner())->create($this->fx['tenant_id'], 'grocery', ['name' => 'Grocery ' . bin2hex(random_bytes(3))], [$this->fx['users']['owner'] => $this->fx['roles']['owner']]);
        $this->assertSame([], $svc->sources($grocery));
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $svc->import($src, $grocery, [$this->product('simple', 0)['id'] => ['q' => 1]]));

        // the wizard refuses a source of another business type
        $this->assertThrowsOmni(ValidationException::class, fn () => $this->newOutlet('grocery'));

        // another account's outlet
        $other = Fixture::workspace('footwear');
        $this->assertThrowsOmni(NotFoundException::class, fn () => $svc->catalogue((int) $other['business_id'], $src));

        // nothing chosen
        $target = $this->newOutlet();
        $this->assertThrowsOmni(ValidationException::class, fn () => $svc->import($src, $target, []));
    }

    public function testPagesRender(): void
    {
        $target = $this->newOutlet();
        $wizard = (string) $this->getPage('settings/outlets')->response()->getBody();
        foreach (['name="products"', 'value="copy"', 'name="copy_from"', 'data-type="footwear"', 'Import products from another outlet'] as $needle) {
            $this->assertStringContainsString($needle, $wizard, $needle);
        }
        $page = (string) $this->getPage('settings/outlets/' . $target . '/import?from=' . $this->fx['business_id'])->response()->getBody();
        foreach (['data-import', 'data-imp-all', 'name="ids[]"', 'data-imp-v=', 'Opening stock for selected', 'Use current stock'] as $needle) {
            $this->assertStringContainsString($needle, $page, $needle);
        }
        if (($dir = getenv('IMPORT_PAGE_DUMP')) !== false && $dir !== '') {
            file_put_contents($dir . '/import.html', $page);
            file_put_contents($dir . '/wizard.html', $wizard);
        }
    }

    public function testStarterAndEmptyChoices(): void
    {
        $db = db_connect();
        $this->assertGreaterThan(0, $db->table('products')->where('business_id', $this->newOutlet('footwear', 'template'))->countAllResults());
        $empty = $this->newOutlet('footwear', 'empty');
        $this->assertSame(0, $db->table('products')->where('business_id', $empty)->countAllResults());
        $this->assertSame(1, $db->table('customers')->where('business_id', $empty)->where('is_walk_in', 1)->countAllResults(), 'an empty outlet still gets its walk-in customer');
    }
}
