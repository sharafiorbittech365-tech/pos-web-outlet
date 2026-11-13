<?php

declare(strict_types=1);

namespace App\Modules\Multisite\Services;

use App\Libraries\ApiSite;
use App\Modules\Orders\Services\OrderPlacement;
use App\Modules\Orders\Services\OrderQueries;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Http\ApiCaller;

/**
 * Orders from the multi-outlet website. Each order goes to ONE authorised outlet and then runs the
 * outlet's normal online-order flow (OrderPlacement: that outlet's checkout settings, prices, VAT,
 * stock, numbering, kitchen) exactly as if the outlet's own storefront had sent it.
 */
final class MultisiteOrders
{
    public function __construct(private readonly MultisiteDirectory $dir, private readonly ApiCaller $caller)
    {
    }

    /**
     * @param array<string, mixed> $in body: outlet_id + the POST /v1/orders fields (no table)
     * @return array<string, mixed> {reference, number, status, subtotal, tax, shipping, total, outlet}
     */
    public function place(array $in): array
    {
        $oid = $in['outlet_id'] ?? null;
        if (! ((is_int($oid) && $oid > 0) || (is_string($oid) && ctype_digit($oid) && (int) $oid > 0))) {
            throw new ValidationException('Choose the outlet for this order.', ['outlet_id' => 'An outlet id is required.']);
        }
        try {
            $outlet = $this->dir->outlet((int) $oid);
        } catch (NotFoundException) {
            throw new ValidationException('This outlet does not take orders on this website.', ['outlet_id' => 'Unknown outlet.']);
        }
        unset($in['outlet_id'], $in['table']);
        $in['channel'] = 'storefront';
        $this->assertSoldBy($outlet, $in['items'] ?? null);

        $ctx = service('tenant');

        return $ctx->runAs($this->dir->tenantId(), $outlet['id'], function () use ($outlet, $in): array {
            // the outlet's own site: its checkout settings and VAT apply; the order is stamped with this website's key
            $siteCaller = new ApiCaller();
            $siteCaller->set(['site_id' => $outlet['site_id'], 'id' => $this->caller->keyId(), 'is_storefront' => true]);
            $order = (new OrderPlacement(new ApiSite($siteCaller), $this->caller, service('orders')))->place($in);

            return $order + ['outlet' => ['id' => $outlet['id'], 'name' => (string) $outlet['name']]];
        });
    }

    /**
     * Tracking by the public reference, for an order of any authorised outlet.
     *
     * @return array<string, mixed>
     */
    public function track(string $reference): array
    {
        $ids = $this->dir->outletIds();
        if (! preg_match('/^[A-Za-z0-9]{4,24}$/', $reference) || $ids === []) {
            throw new NotFoundException('No order with that reference.');
        }
        $bid = db_connect()->table('orders')->select('business_id')->where('tenant_id', $this->dir->tenantId())->whereIn('business_id', $ids)
            ->where('web_ref', strtoupper($reference))->get()->getRow('business_id');
        if ($bid === null) {
            throw new NotFoundException('No order with that reference.');
        }
        $outlet = $this->dir->outlet((int) $bid);

        return service('tenant')->runAs($this->dir->tenantId(), $outlet['id'], static fn (): array => (new OrderQueries(service('orders')))->track($reference)
            + ['outlet' => ['id' => $outlet['id'], 'name' => (string) $outlet['name'], 'phone' => $outlet['phone']]]);
    }

    /**
     * Every item must be a product the chosen outlet sells online — products of other outlets are
     * refused here with a clear message (the core would refuse them too).
     *
     * @param mixed $items
     */
    private function assertSoldBy(array $outlet, mixed $items): void
    {
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            return; // OrderPlacement reports the missing items
        }
        $ids = [];
        foreach ($items as $it) {
            $pid = is_array($it) ? ($it['product_id'] ?? null) : null;
            if ((is_int($pid) && $pid > 0) || (is_string($pid) && ctype_digit($pid))) {
                $ids[] = (int) $pid;
            }
        }
        if ($ids === []) {
            return;
        }
        $sold = array_map('intval', array_column(db_connect()->table('products')->select('id')
            ->where('tenant_id', $this->dir->tenantId())->where('business_id', $outlet['id'])->whereIn('id', array_values(array_unique($ids)))
            ->where('is_active', 1)->where('is_online', 1)->where('deleted_at', null)->get()->getResultArray(), 'id'));
        $errors = [];
        foreach ($items as $i => $it) {
            $pid = is_array($it) ? (int) ($it['product_id'] ?? 0) : 0;
            if ($pid > 0 && ! in_array($pid, $sold, true)) {
                $errors["items.{$i}.product_id"] = 'Not sold online by ' . $outlet['name'] . '.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Some items are not available at ' . $outlet['name'] . '.', $errors);
        }
    }
}
