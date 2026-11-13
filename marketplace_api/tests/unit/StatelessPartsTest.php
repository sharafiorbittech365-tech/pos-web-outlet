<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\BuyerToken;
use App\Modules\Checkout\Services\CartService;
use App\Modules\Checkout\Services\CheckoutService;
use App\Modules\Location\Services\LocationStore;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The parts that replaced the CodeIgniter marketplace's session: buyer tokens, the client-held
 * cart and location (both cleaned on every request), and checkout tokens.
 *
 * @internal
 */
final class StatelessPartsTest extends CIUnitTestCase
{
    public function testBuyerTokenRoundTripAndTampering(): void
    {
        $t = new BuyerToken('secret-key', 30);
        $now = 1_800_000_000;
        $issued = $t->issue(42, $now);
        $this->assertSame($now + 30 * 86400, $issued['expires_at']);
        $this->assertSame(42, $t->verify($issued['token'], $now + 10));

        // another id with the same signature, a wrong key, expiry and junk are all refused
        $this->assertNull($t->verify(preg_replace('/^42\./', '43.', $issued['token']), $now));
        $this->assertNull((new BuyerToken('other-key'))->verify($issued['token'], $now));
        $this->assertNull($t->verify($issued['token'], $issued['expires_at'] + 1));
        $this->assertNull($t->verify('', $now));
        $this->assertNull($t->verify('0.1800000000.' . str_repeat('a', 64), $now));
    }

    public function testCartIsCleaned(): void
    {
        $cart = CartService::normalise([
            '4' => ['75:0' => 2, '76:3' => '1.5', 'x:1' => 1, '77:0' => 0, '78:0' => -1, '79:0' => 500],
            'abc' => ['1:0' => 1],
            '0' => ['1:0' => 1],
            '5' => 'not-lines',
        ]);
        $this->assertSame([4 => ['75:0' => 2.0, '76:3' => 1.5, '79:0' => 99.0]], $cart);
        $this->assertSame(103, CartService::count($cart));
        $this->assertSame([], CartService::normalise('junk'));

        $many = [];
        for ($i = 1; $i <= 60; $i++) {
            $many['9'][$i . ':0'] = 1;
        }
        $this->assertCount(CartService::MAX_LINES, CartService::normalise($many)[9]);
    }

    public function testLocationIsCleaned(): void
    {
        $this->assertNull(LocationStore::clean(['lat' => 'x', 'lng' => 1]));
        $this->assertNull(LocationStore::clean(['lat' => 91, 'lng' => 1]));
        $this->assertSame(
            ['lat' => 23.74612, 'lng' => 90.37421, 'area' => 'Dhanmondi', 'city' => 'Dhaka', 'source' => 'manual'],
            LocationStore::clean(['lat' => '23.746123', 'lng' => 90.374209, 'area' => ' <b>Dhanmondi</b> ', 'city' => 'Dhaka', 'source' => 'evil']),
        );
    }

    public function testCheckoutTokensAndReferences(): void
    {
        $this->assertTrue(CheckoutService::validToken(str_repeat('ab', 16)));
        $this->assertFalse(CheckoutService::validToken(str_repeat('AB', 16)));
        $this->assertFalse(CheckoutService::validToken('../../etc/passwd'));
        $tok = str_repeat('0f', 16);
        $this->assertSame(CheckoutService::reference($tok, 4), CheckoutService::reference($tok, 4));
        $this->assertNotSame(CheckoutService::reference($tok, 4), CheckoutService::reference($tok, 5));
        $this->assertMatchesRegularExpression('/^M[0-9A-F]{9}$/', CheckoutService::reference($tok, 4));
    }
}
