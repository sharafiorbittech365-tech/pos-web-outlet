<?php

declare(strict_types=1);

use Tests\Support\PosA\Fixture;
use Tests\Support\PosA\TestCase;

/**
 * @internal
 */
final class AuthTest extends TestCase
{
    public function testOwnerSignsInAndLandsOnTheDashboard(): void
    {
        $r = $this->postForm('login', ['email' => $this->fx['email'], 'password' => Fixture::PASSWORD], []);
        $r->assertRedirect();
        $this->assertStringEndsWith('/dashboard', $r->getRedirectUrl());
        $this->assertSame($this->fx['tenant_id'], (int) $_SESSION['pos']['tenant_id']);
        $this->assertSame($this->fx['business_id'], (int) $_SESSION['pos']['business_id']);
        $this->assertSame($this->fx['users']['owner'], (int) $_SESSION['pos']['user_id']);
    }

    public function testWrongPasswordIsRefusedWithTheSameMessage(): void
    {
        $r = $this->postForm('login', ['email' => $this->fx['email'], 'password' => 'nope-nope'], []);
        $r->assertRedirect();
        $r->assertSessionHas('error', 'Wrong email or password.');
        $r2 = $this->postForm('login', ['email' => 'nobody@example.test', 'password' => 'nope-nope'], []);
        $r2->assertSessionHas('error', 'Wrong email or password.');
        $this->assertArrayNotHasKey('pos', $_SESSION);
    }

    public function testAccountOnAnotherServerIsSentToThatServersLogin(): void
    {
        $other = Fixture::workspace('grocery', 'pos-2');
        $base = rtrim((string) db_connect()->table('pos_servers')->where('code', 'pos-2')->get()->getRow('base_url'), '/');
        $r = $this->postForm('login', ['email' => $other['email'], 'password' => Fixture::PASSWORD], []);
        $r->assertRedirect();
        $this->assertStringStartsWith($base . '/login?server=moved', $r->getRedirectUrl());
        $this->assertArrayNotHasKey('pos', $_SESSION);
    }

    public function testTenantServerGuardRejectsASessionOfAnotherServer(): void
    {
        $other = Fixture::workspace('grocery', 'pos-2');
        $this->fx = $other;
        $r = $this->getPage('dashboard');
        $r->assertRedirect();
        $this->assertStringContainsString('/login', $r->getRedirectUrl());
        $this->assertStringNotContainsString('localhost:8082', $r->getRedirectUrl());
    }

    public function testPinSwitchesThePersonAtTheTill(): void
    {
        $pos = ['tenant_id' => $this->fx['tenant_id'], 'business_id' => $this->fx['business_id'], 'user_id' => 0];
        $r = $this->postForm('switch-user', ['user_id' => $this->fx['users']['manager'], 'pin' => '2222'], $pos);
        $r->assertRedirect();
        $this->assertSame($this->fx['users']['manager'], (int) $_SESSION['pos']['user_id']);

        $bad = $this->postForm('switch-user', ['user_id' => $this->fx['users']['cashier'], 'pin' => '9999'], $pos);
        $bad->assertRedirect();
        $bad->assertSessionHas('error', 'Wrong PIN.');
        $this->assertNotSame($this->fx['users']['cashier'], (int) ($_SESSION['pos']['user_id'] ?? 0));
    }

    public function testLockedTillRedirectsToTheLockScreen(): void
    {
        $r = $this->postForm('lock', []);
        $r->assertRedirect();
        $this->assertStringEndsWith('/switch-user', $r->getRedirectUrl());
        $this->assertSame(0, (int) $_SESSION['pos']['user_id']);
    }

    public function testEmptyOutletGoesThroughTheSetUpStep(): void
    {
        $db = db_connect();
        $bid = $this->fx['business_id'];
        $db->table('products')->where('business_id', $bid)->delete();
        $db->table('businesses')->where('id', $bid)->update(['settings' => json_encode(['setup' => false, 'features' => (object) [], 'payments' => ['cash']])]);
        $r = $this->postForm('login', ['email' => $this->fx['email'], 'password' => Fixture::PASSWORD], []);
        $this->assertStringEndsWith('/welcome', $r->getRedirectUrl());
        $done = $this->postForm('welcome', ['type' => 'grocery', 'samples' => '1']);
        $this->assertStringEndsWith('/dashboard', $done->getRedirectUrl());
        $s = json_decode((string) $db->table('businesses')->select('settings')->where('id', $bid)->get()->getRow('settings'), true);
        $this->assertTrue($s['setup']);
        $this->assertGreaterThan(0, $db->table('products')->where('business_id', $bid)->countAllResults());
    }
}
