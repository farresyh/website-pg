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

    public function test_create_makes_an_active_reseller_with_its_wallet_account(): void
    {
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 1]);

        $reseller = $this->service()->create([
            'business_name' => 'Acme', 'contact_name' => 'Ali', 'email' => 'ali@example.test',
            'phone' => '0123456789', 'notes' => 'n', 'reseller_tier_id' => $tier->id,
        ]);

        $this->assertTrue($reseller->is_active);
        $this->assertSame($tier->id, $reseller->reseller_tier_id);
        $this->assertSame('ali@example.test', $reseller->email);
        $this->assertTrue(LedgerAccount::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)->where('owner_id', $reseller->id)->exists());
    }

    public function test_create_without_a_tier_still_works_until_the_callers_require_one(): void
    {
        $reseller = $this->service()->create(['business_name' => 'Plain']);

        $this->assertNull($reseller->reseller_tier_id);
        $this->assertTrue($reseller->is_active);
    }

    public function test_add_portal_user_makes_a_passwordless_user_and_sends_the_invite(): void
    {
        Http::fake();
        $reseller = $this->service()->create(['business_name' => 'Acme']);

        $user = $this->service()->addPortalUser($reseller, 'Staff', 'staff@example.test');

        $this->assertSame(AccountOwnerType::Reseller, $user->owner_type);
        $this->assertSame($reseller->id, $user->owner_id);
        $this->assertNull($user->password);
        $this->assertTrue($user->is_active);
        Http::assertSentCount(1);
    }

    public function test_a_failed_invite_rolls_the_user_back(): void
    {
        Http::fake(fn () => throw new \RuntimeException('Plunk is down'));
        $reseller = $this->service()->create(['business_name' => 'Acme']);

        try {
            $this->service()->addPortalUser($reseller, 'Staff', 'staff@example.test');
            $this->fail('the invite failure must surface');
        } catch (\Throwable) {
        }

        $this->assertSame(0, AffiliateUser::query()->where('email', 'staff@example.test')->count());
        $this->assertSame(1, Reseller::query()->count());
    }
}
