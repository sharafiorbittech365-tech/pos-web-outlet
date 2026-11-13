<?php

declare(strict_types=1);

use App\Modules\Multisite\Services\MultisiteAdmin;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\MultisiteKeys;
use OmniPOS\Core\Services\OutletProvisioner;
use Tests\Support\PosA\TestCase;

/**
 * POS › Website › Multi-outlet website (owner only): settings, outlets shown, website key.
 *
 * @internal
 */
final class MultisiteTest extends TestCase
{
    private function secondOutlet(): int
    {
        return (new OutletProvisioner())->create($this->fx['tenant_id'], 'grocery', ['name' => 'Second ' . $this->fx['tenant_id']], [$this->fx['users']['owner'] => $this->fx['roles']['owner']]);
    }

    public function testOwnerPicksOutletsAndPublishes(): void
    {
        $second = $this->secondOutlet();
        $admin = MultisiteAdmin::forCurrent();
        $ms = $admin->website();
        $this->assertSame(0, (int) $ms['is_enabled']);
        $this->assertCount(2, $admin->outlets());

        // publishing needs at least one outlet
        $in = ['slug' => $ms['slug'], 'home_business_id' => $this->fx['business_id'], 'is_enabled' => '1', 'name' => 'All Stores'];
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $admin->save($in));

        $this->assertSame(1, $admin->saveOutlets([(string) $second], [$second => 3]));
        $this->assertNotNull(db_connect()->table('sites')->where('business_id', $second)->get()->getRow('id'), 'the outlet gets a website record for its checkout settings');
        $admin->save($in);
        $row = db_connect()->table('multisites')->where('tenant_id', $this->fx['tenant_id'])->get()->getRowArray();
        $this->assertSame([1, 'All Stores'], [(int) $row['is_enabled'], $row['name']]);
        $on = array_values(array_filter($admin->outlets(), static fn (array $o): bool => $o['on']));
        $this->assertSame([$second], array_column($on, 'id'));

        // another tenant's outlet id is ignored
        $other = \Tests\Support\PosA\Fixture::workspace('grocery');
        $this->assertSame(1, $admin->saveOutlets([(string) $second, (string) $other['business_id']], []));
        $this->dontSeeInDatabase('multisite_outlets', ['business_id' => $other['business_id']]);
        $this->assertThrowsOmni(ValidationException::class, fn () => $admin->save(['slug' => $ms['slug'], 'home_business_id' => $other['business_id']]));
        $this->assertThrowsOmni(ValidationException::class, fn () => $admin->save(['slug' => 'Bad Slug!', 'home_business_id' => $this->fx['business_id']]));

        // switching every outlet off unpublishes
        $admin->saveOutlets([], []);
        $this->assertSame(0, (int) db_connect()->table('multisites')->select('is_enabled')->where('tenant_id', $this->fx['tenant_id'])->get()->getRow('is_enabled'));
        $this->seeInDatabase('activity_log', ['action' => 'Multi-outlet website saved']);
    }

    public function testKeyIsShownOnceAndRotates(): void
    {
        $admin = MultisiteAdmin::forCurrent();
        $first = $admin->issueKey();
        $this->assertStringStartsWith('mk_live_', $first['key']);
        $this->assertSame($first['last4'], $admin->key()['last4']);
        $this->dontSeeInDatabase('api_keys', ['key_hash' => $first['key']]);
        $ms = $admin->website();
        $this->assertSame((int) $ms['id'], (new MultisiteKeys())->resolve($first['key'])['multisite_id']);

        $second = $admin->issueKey();
        $this->assertNotSame($first['key'], $second['key']);
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\AuthenticationException::class, fn () => (new MultisiteKeys())->resolve($first['key']));
        $admin->revokeKey();
        $this->assertNull($admin->key());
    }

    public function testOnlyTheOwnerAndPagesRender(): void
    {
        $r = $this->getPage('website/multisite');
        $r->assertOK();
        $r->assertSee('Outlets on the website');
        $this->getPage('website')->assertSee('Multi-outlet');
        $this->act('manager');
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => MultisiteAdmin::forCurrent());
    }
}
