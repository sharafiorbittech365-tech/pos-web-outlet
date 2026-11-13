<?php

declare(strict_types=1);

use OmniPOS\Core\Database\Seeds\PlatformSeeder;
use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * @internal
 */
final class AuthTest extends FeatureTestCase
{
    private function login(string $email, string $password): \CodeIgniter\Test\TestResponse
    {
        \Config\Services::resetSingle('security');

        return $this->withSession(['csrf_test_name' => self::TOKEN])->call('post', 'login', ['email' => $email, 'password' => $password, 'csrf_test_name' => self::TOKEN]);
    }

    public function testLoginPageLoads(): void
    {
        $res = $this->withSession([])->call('get', 'login');
        $res->assertOK();
        $res->assertSee('Staff sign-in');
    }

    public function testSignInWithCorrectPassword(): void
    {
        $res = $this->login('owner@omnipos.test', PlatformSeeder::DEMO_PASSWORD);
        $res->assertRedirectTo(site_url('overview'));
        $this->assertSame($this->adminId('owner'), session('admin_id'));
        $this->seeInDatabase('platform_audit', ['action' => 'Signed in', 'detail' => 'owner@omnipos.test']);
        $this->assertNotNull(db_connect()->table('platform_admins')->where('email', 'owner@omnipos.test')->get()->getRow('last_login_at'));
    }

    public function testWrongPasswordIsRefusedWithTheSameMessage(): void
    {
        $res = $this->login('finance@omnipos.test', 'not-the-password');
        $res->assertRedirect();
        $res->assertSessionHas('error', 'Wrong email or password.');
        $this->assertNull(session('admin_id'));

        $res = $this->login('nobody@omnipos.test', 'whatever');
        $res->assertSessionHas('error', 'Wrong email or password.');
        $this->seeInDatabase('auth_logins', ['app' => 'superadmin', 'identifier' => 'finance@omnipos.test', 'success' => 0]);
    }

    public function testDisabledStaffCannotSignIn(): void
    {
        db_connect()->table('platform_admins')->where('email', 'sales@omnipos.test')->update(['is_active' => 0]);
        try {
            $this->login('sales@omnipos.test', PlatformSeeder::DEMO_PASSWORD)->assertSessionHas('error', 'Wrong email or password.');
        } finally {
            db_connect()->table('platform_admins')->where('email', 'sales@omnipos.test')->update(['is_active' => 1]);
        }
    }

    public function testPagesNeedASignedInStaffMember(): void
    {
        \Config\Services::resetSingle('adminSession');
        $this->withSession([])->call('get', 'overview')->assertRedirectTo(site_url('login'));
        $this->withSession([])->call('get', 'tenants')->assertRedirectTo(site_url('login'));
    }
}
