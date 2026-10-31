<?php

namespace Tests\Feature\Services\Reseller;

use App\Models\AffiliateUser;
use App\Models\LedgerAccount;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Reseller\ResellerProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-133 decision 21: one place creates a reseller (the row, its wallet ledger account) and one
 * place adds a portal login with the set-password invite. The admin screens and the application
 * approval call it, so there is never a second copy.
 */
class ResellerProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ResellerProvisioningService
    {
        return app(ResellerProvisioningService::class);
    }

    private function tier(): ResellerTier
    {
        return ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 1]);
    }

    /** @return array<string, mixed> */
    private function details(array $overrides = []): array
    {
        return $overrides + [
            'business_name' => 'Acme', 'contact_name' => 'Ali', 'email' => 'ali@example.test',
            'phone' => '0123456789', 'notes' => 'n', 'reseller_tier_id' => $this->tier()->id,
        ];
    }

    public function test_provision_makes_the_reseller_its_wallet_account_and_a_portal_login_with_the_invite(): void
    {
        Http::fake();

        $reseller = $this->service()->provision($this->details());

        $this->assertTrue($reseller->is_active);
        $this->assertNotNull($reseller->reseller_tier_id);
        $this->assertNull($reseller->internal_reason);
        $this->assertTrue(LedgerAccount::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)->where('owner_id', $reseller->id)->exists());

        $user = AffiliateUser::query()->sole();
        $this->assertSame(AccountOwnerType::Reseller, $user->owner_type);
        $this->assertSame($reseller->id, $user->owner_id);
        $this->assertSame('Ali', $user->name);
        $this->assertSame('ali@example.test', $user->email);
        $this->assertNull($user->password);
        Http::assertSentCount(1);
    }

    /** ADR-133 decision 21: a reseller with no tier cannot order or add balance, so none is made. */
    public function test_provision_without_a_tier_makes_nothing(): void
    {
        Http::fake();

        try {
            $this->service()->provision($this->details(['reseller_tier_id' => null]));
            $this->fail('A reseller was provisioned without a tier.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(0, Reseller::query()->count());
        $this->assertSame(0, LedgerAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_failed_invite_leaves_no_reseller_account_or_login(): void
    {
        Http::fake(fn () => throw new \RuntimeException('Plunk is down'));

        try {
            $this->service()->provision($this->details());
            $this->fail('the invite failure must surface');
        } catch (\Throwable) {
        }

        $this->assertSame(0, Reseller::withTrashed()->count());
        $this->assertSame(0, LedgerAccount::query()->count());
        $this->assertSame(0, AffiliateUser::query()->count());
    }

    public function test_an_internal_account_keeps_its_reason(): void
    {
        Http::fake();

        $reseller = $this->service()->provision($this->details(['internal_reason' => 'Test account for CHIP review']));

        $this->assertSame('Test account for CHIP review', $reseller->fresh()->internal_reason);
    }

    public function test_add_portal_user_makes_a_passwordless_user_and_sends_the_invite(): void
    {
        Http::fake();
        $reseller = $this->service()->provision($this->details());

        $user = $this->service()->addPortalUser($reseller, 'Staff', 'staff@example.test');

        $this->assertSame(AccountOwnerType::Reseller, $user->owner_type);
        $this->assertSame($reseller->id, $user->owner_id);
        $this->assertNull($user->password);
        $this->assertTrue($user->is_active);
        Http::assertSentCount(2);
    }

    public function test_a_failed_second_invite_rolls_only_that_user_back(): void
    {
        Http::fake();
        $reseller = $this->service()->provision($this->details());
        Http::fake(fn () => throw new \RuntimeException('Plunk is down'));

        try {
            $this->service()->addPortalUser($reseller, 'Staff', 'staff@example.test');
            $this->fail('the invite failure must surface');
        } catch (\Throwable) {
        }

        $this->assertSame(0, AffiliateUser::query()->where('email', 'staff@example.test')->count());
        $this->assertSame(1, Reseller::query()->count());
    }
}
