<?php

declare(strict_types=1);

use Tests\Support\PosB\FeatureTestCase;

/**
 * POS › Website › Self-hosted storefront and domain control.
 *
 * @internal
 */
final class SelfHostedTest extends FeatureTestCase
{
    private function site(): array
    {
        $this->postForm('website/mode', ['mode' => 'storefront'])->assertRedirect();

        return db_connect()->table('sites')->where('business_id', $this->fx['business_id'])->get()->getRowArray();
    }

    public function testClientSecretIsShownOnceAndOnlyItsHashIsStored(): void
    {
        $site = $this->site();
        $this->postForm('website/clients', ['client_name' => 'www.myshop.test'])->assertRedirect();
        $flash = $_SESSION['new_client'] ?? null;
        $this->assertIsArray($flash, 'The secret is handed to the next page once (flash).');
        $this->assertMatchesRegularExpression('/^sfc_[A-Za-z0-9]{24}$/', $flash['client_id']);
        $this->assertMatchesRegularExpression('/^sfs_[A-Za-z0-9]{48}$/', $flash['client_secret']);

        $row = db_connect()->table('storefront_clients')->where('client_id', $flash['client_id'])->get()->getRowArray();
        $this->assertSame((int) $site['id'], (int) $row['site_id']);
        $this->assertSame((int) $this->fx['business_id'], (int) $row['business_id']);
        $this->assertSame(hash('sha256', $flash['client_secret']), $row['secret_hash']);
        $this->assertStringNotContainsString($flash['client_secret'], (string) json_encode($row));

        // the list shows the id and only the last 4 of the secret
        $page = $this->getPage('website', ['tab' => 'selfhosted']);
        $page->assertOK();
        $page->assertSee($flash['client_id']);
        $page->assertSee('sfs_…' . $row['secret_last4']);
        $page->assertDontSee($flash['client_secret']);
    }

    public function testRotatePauseAndRevoke(): void
    {
        $this->site();
        $this->postForm('website/clients', ['client_name' => 'Site'])->assertRedirect();
        $first = $_SESSION['new_client'];
        $row = db_connect()->table('storefront_clients')->where('client_id', $first['client_id'])->get()->getRowArray();

        $this->postForm('website/clients/' . $row['id'] . '/rotate')->assertRedirect();
        $second = $_SESSION['new_client'];
        $this->assertSame($first['client_id'], $second['client_id']);
        $this->assertNotSame($first['client_secret'], $second['client_secret']);
        $this->assertSame(hash('sha256', $second['client_secret']), db_connect()->table('storefront_clients')->where('id', $row['id'])->get()->getRow('secret_hash'));

        $this->postForm('website/clients/' . $row['id'] . '/toggle')->assertRedirect();
        $this->assertSame(0, (int) db_connect()->table('storefront_clients')->where('id', $row['id'])->get()->getRow('is_active'));

        $this->postForm('website/clients/' . $row['id'] . '/revoke')->assertRedirect();
        $this->assertNotNull(db_connect()->table('storefront_clients')->where('id', $row['id'])->get()->getRow('revoked_at'));
    }

    public function testDomainEnableDisableHostingAndPlatformBlock(): void
    {
        $site = $this->site();
        $this->postForm('website/domains', ['host' => 'shop.selfhosted.test'])->assertRedirect();
        $d = db_connect()->table('site_domains')->where('site_id', $site['id'])->where('host', 'shop.selfhosted.test')->get()->getRowArray();
        $this->assertSame(1, (int) $d['is_enabled']);
        $this->assertSame('platform', $d['hosting']);

        $this->postForm('website/domains/' . $d['id'] . '/hosting', ['hosting' => 'external'])->assertRedirect();
        $this->postForm('website/domains/' . $d['id'] . '/toggle', ['enabled' => '0'])->assertRedirect();
        $d = db_connect()->table('site_domains')->where('id', $d['id'])->get()->getRowArray();
        $this->assertSame('external', $d['hosting']);
        $this->assertSame(0, (int) $d['is_enabled']);

        // a platform block (Super Admin) cannot be lifted by the owner
        service('domains')->setBlocked((int) $d['id'], true, 'Abuse report', ['name' => 'Platform']);
        $e = $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\BusinessRuleException::class, fn () => $this->postForm('website/domains/' . $d['id'] . '/toggle', ['enabled' => '1']));
        $this->assertStringContainsString('blocked by ' . \OmniPOS\Ui\Brand::name(), $e->getMessage());
        $this->assertSame(0, (int) db_connect()->table('site_domains')->where('id', $d['id'])->get()->getRow('is_enabled'));
    }

    public function testCannotManageAnotherOutletsClient(): void
    {
        $this->site();
        $this->postForm('website/clients', ['client_name' => 'Mine'])->assertRedirect();
        // a client of some other site id must not be reachable from this outlet's routes
        $this->bind('owner');
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\NotFoundException::class, static fn () => service('storefrontClients')->revoke(999999, 999999));
    }
}
