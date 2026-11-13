<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;
use Tests\Support\PosB\FeatureTestCase;

/**
 * @internal
 */
final class UsersTest extends FeatureTestCase
{
    protected function fixtureOptions(): array
    {
        // owner + cashier already use 2 of 3 seats
        return ['limit_users' => 3];
    }

    public function testCashierIsDenied(): void
    {
        $e = $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('cashier')->getPage('users'));
        $this->assertSame(403, $e->httpStatus());
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('cashier')->postForm('users/roles', ['name' => 'Sneaky', 'perms' => ['users.manage']]));
    }

    public function testUserLimitIsEnforced(): void
    {
        $roles = [$this->fx['business_id'] => $this->fx['roles']['cashier']];
        $this->postForm('users', ['name' => 'Third', 'pin' => '3333', 'is_active' => '1', 'roles' => $roles])->assertRedirect();
        $third = (int) db_connect()->table('users')->where('tenant_id', $this->fx['tenant_id'])->where('name', 'Third')->get()->getRow('id');
        $this->assertGreaterThan(0, $third);
        $this->assertTrue(password_verify('3333', (string) db_connect()->table('users')->where('id', $third)->get()->getRow('pin_hash')));

        $e = $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('users', ['name' => 'Fourth', 'pin' => '4444', 'is_active' => '1', 'roles' => $roles]));
        $this->assertStringContainsString('3 active users', $e->getMessage());
        $this->assertSame(0, db_connect()->table('users')->where('tenant_id', $this->fx['tenant_id'])->where('name', 'Fourth')->countAllResults());

        // a disabled member does not take a seat
        $this->postForm('users', ['name' => 'Fourth', 'pin' => '4444', 'is_active' => '0', 'roles' => $roles])->assertRedirect();
        $this->assertSame(1, db_connect()->table('users')->where('tenant_id', $this->fx['tenant_id'])->where('name', 'Fourth')->where('is_active', 0)->countAllResults());
    }

    public function testPinMustBeUniqueAndFourDigits(): void
    {
        $roles = [$this->fx['business_id'] => $this->fx['roles']['cashier']];
        $e = $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('users', ['name' => 'Dup', 'pin' => '2222', 'is_active' => '1', 'roles' => $roles]));
        $this->assertArrayHasKey('pin', $e->details());
        $e = $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('users', ['name' => 'Short', 'pin' => '12', 'is_active' => '1', 'roles' => $roles]));
        $this->assertArrayHasKey('pin', $e->details());
    }

    public function testRoleMatrixAndOwnerRoleLocked(): void
    {
        $this->postForm('users/roles/' . $this->fx['roles']['cashier'], ['name' => 'Cashier', 'home' => 'pos', 'max_discount' => '5', 'color' => '#16A34A', 'icon' => 'banknote',
            'perms' => ['pos.sell', 'orders.view', 'reports.view', 'not.a.permission']])->assertRedirect();
        $perms = json_decode((string) db_connect()->table('roles')->where('id', $this->fx['roles']['cashier'])->get()->getRow('permissions'), true);
        $this->assertSame(['pos.sell', 'orders.view', 'reports.view'], $perms);
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('users/roles/' . $this->fx['roles']['owner'], ['name' => 'Boss', 'perms' => []]));

        foreach (['users', 'users/new', 'users/roles', 'users/activity', 'users/' . $this->fx['users']['cashier']] as $uri) {
            $this->getPage($uri)->assertOK();
        }
    }
}
