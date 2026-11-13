<?php

declare(strict_types=1);

use OmniPOS\Core\Services\StorefrontGateway;
use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * Super Admin › System › Storefront gateway: generate, rotate with a grace period, disable.
 *
 * @internal
 */
final class GatewayTest extends FeatureTestCase
{
    private function token(): string
    {
        return (string) ($_SESSION['gatewayToken'] ?? '');
    }

    public function testGenerateRotateAndDisable(): void
    {
        db_connect()->table('platform_settings')->where('setting_key', StorefrontGateway::SETTING)->delete();
        service('catalog')->flush();
        $gw = service('storefrontGateway');

        $this->postForm('system/gateway/rotate', ['grace' => 0])->assertRedirect();
        $first = $this->token();
        $this->assertMatchesRegularExpression('/^sfg_[0-9a-f]{48}$/', $first);
        $stored = (string) db_connect()->table('platform_settings')->where('setting_key', StorefrontGateway::SETTING)->get()->getRow('value');
        $this->assertStringNotContainsString($first, $stored, 'only the hash is stored');
        $this->assertTrue($gw->matches($first));
        $this->assertSame('platform', $gw->status()['source']);
        $this->seeInDatabase('platform_audit', ['action' => 'Storefront gateway token generated']);

        // rotate: the old token keeps working for the grace period
        $this->postForm('system/gateway/rotate', ['grace' => 24])->assertRedirect();
        $second = $this->token();
        $this->assertNotSame($first, $second);
        $this->assertTrue($gw->matches($second));
        $this->assertTrue($gw->matches($first));
        $this->assertSame(substr($first, -4), $gw->status()['previous']['last4']);

        // rotate without grace: the old token stops at once
        $this->postForm('system/gateway/rotate', ['grace' => 0])->assertRedirect();
        $third = $this->token();
        $this->assertTrue($gw->matches($third));
        $this->assertFalse($gw->matches($second));

        $page = $this->getPage('system');
        $page->assertOK();
        $page->assertSee('…' . substr($third, -4));
        $this->assertStringNotContainsString($third, (string) $page->getBody(), 'the token is shown once, not on later visits');

        $this->postForm('system/gateway/disable')->assertRedirect();
        $this->assertFalse($gw->matches($third));
        $this->assertSame('disabled', $gw->status()['source']);
        $this->seeInDatabase('platform_audit', ['action' => 'Storefront gateway disabled']);

        // a new token brings it back
        $this->postForm('system/gateway/rotate', ['grace' => 0])->assertRedirect();
        $this->assertTrue($gw->matches($this->token()));
    }

    public function testUnknownGracePeriodIsRefused(): void
    {
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\BusinessRuleException::class, fn () => $this->postForm('system/gateway/rotate', ['grace' => 5]));
    }
}
