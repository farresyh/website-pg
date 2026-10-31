<?php

namespace Tests\Feature\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateUser;
use App\Services\Affiliate\AffiliateWithdrawalService;
use App\Services\Auth\AccountOwnerType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-133 decision 13: "has bank details" is one predicate. The payout plan, the portal's
 * withdrawal screen and the readiness checklist must never disagree about it.
 */
class AffiliateHasBankDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function affiliate(array $bank = []): Affiliate
    {
        return Affiliate::query()->create(array_merge([
            'business_name' => 'Acme', 'markup_pct' => 10, 'status' => 'active',
        ], $bank));
    }

    public function test_all_three_bank_fields_are_required(): void
    {
        $full = ['bank_name' => 'Maybank', 'bank_account_no' => '1234567', 'bank_account_holder' => 'Acme Sdn Bhd'];

        $this->assertTrue($this->affiliate($full)->hasBankDetails());
        $this->assertFalse($this->affiliate()->hasBankDetails());

        foreach (array_keys($full) as $missing) {
            $partial = $full;
            unset($partial[$missing]);

            $this->assertFalse($this->affiliate($partial)->hasBankDetails(), "missing {$missing}");
        }
    }

    public function test_the_payout_plan_skips_exactly_when_the_predicate_says_no_bank(): void
    {
        $with = $this->affiliate(['bank_name' => 'Maybank', 'bank_account_no' => '1', 'bank_account_holder' => 'Acme']);
        $without = $this->affiliate(['bank_name' => 'Maybank']);

        $service = app(AffiliateWithdrawalService::class);

        $this->assertNotSame('no_bank', $service->plan($with)['skip']);
        $this->assertSame('no_bank', $service->plan($without)['skip']);
    }

    public function test_the_portal_withdrawal_screen_says_yes_with_bank_details(): void
    {
        $this->assertPortalAgrees(['bank_name' => 'Maybank', 'bank_account_no' => '1', 'bank_account_holder' => 'Acme']);
    }

    public function test_the_portal_withdrawal_screen_says_no_without_them(): void
    {
        $this->assertPortalAgrees([]);
    }

    /** One affiliate per test: Sanctum keeps the first authenticated user for the rest of a test. */
    private function assertPortalAgrees(array $bank): void
    {
        $affiliate = $this->affiliate($bank);
        $user = AffiliateUser::query()->create([
            'owner_type' => AccountOwnerType::Affiliate->value, 'owner_id' => $affiliate->id,
            'name' => 'Owner', 'email' => 'owner@example.test', 'password' => bcrypt('password'), 'is_active' => true,
        ]);

        $this->withToken($user->createToken('t', ['affiliate'])->plainTextToken)
            ->getJson('/api/affiliate/withdrawals')
            ->assertOk()
            ->assertJsonPath('payout.has_bank_details', $affiliate->hasBankDetails());
    }
}
