<?php

namespace Tests\Feature\Http\Controllers\Affiliate;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateUser;
use App\Models\Withdrawal;
use App\Services\Affiliate\AffiliateWithdrawalService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-059 59c / ADR-132: the affiliate-portal side of WTH-1..5 — read only;
 * the platform opens the ticket (AffiliateMonthlyPayoutTest). Approval /
 * rejection / completion stay on the admin flow, unchanged.
 */
class AffiliateWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
    }

    private function affiliate(string $name = 'Acme'): Affiliate
    {
        return Affiliate::query()->create([
            'business_name' => $name,
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567',
            'bank_account_holder' => "{$name} Sdn Bhd",
        ]);
    }

    private function tokenFor(Affiliate $affiliate): string
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => 'affiliate',
            'owner_id' => $affiliate->id,
            'name' => 'Staff',
            'email' => 'staff+'.$affiliate->id.'@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    public function test_index_returns_balance_prefill_and_only_this_affiliates_withdrawals(): void
    {
        $mine = $this->affiliate('Mine');
        $other = $this->affiliate('Other');
        $this->ledger->credit(LedgerOwnerType::Affiliate, $mine->id, 50000, 'order_profit', 'order', 1);

        Withdrawal::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $mine->id, 'amount' => 10000,
            'bank_name' => 'Maybank', 'bank_account_no' => '1', 'bank_account_holder' => 'Mine',
            'status' => 'pending', 'requested_by' => null,
        ]);
        Withdrawal::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $other->id, 'amount' => 20000,
            'bank_name' => 'CIMB', 'bank_account_no' => '2', 'bank_account_holder' => 'Other',
            'status' => 'pending', 'requested_by' => null,
        ]);

        $this->withToken($this->tokenFor($mine))->getJson('/api/affiliate/withdrawals')
            ->assertOk()
            ->assertJsonPath('balance', 50000)
            ->assertJsonPath('prefill.bank_name', 'Maybank')
            ->assertJsonCount(1, 'withdrawals');
    }

    public function test_index_returns_the_payout_block_with_the_fee_reserve_and_bank_flag(): void
    {
        $affiliate = $this->affiliate();
        $this->travelTo($this->midLastMonth());
        $this->ledger->credit(LedgerOwnerType::Affiliate, $affiliate->id, 50000, 'order_profit', 'order', 1);
        $this->travelBack();

        $token = $this->tokenFor($affiliate);
        $this->withToken($token)->getJson('/api/affiliate/withdrawals')
            ->assertOk()
            ->assertJsonPath('payout.min_payout_sen', 1000)
            ->assertJsonPath('payout.reserved_for_fee_sen', 0)
            ->assertJsonPath('payout.fee_due_at', null)
            ->assertJsonPath('payout.has_bank_details', true);

        $affiliate->update(['bank_name' => null]);
        $this->withToken($token)->getJson('/api/affiliate/withdrawals')
            ->assertJsonPath('payout.has_bank_details', false);
    }

    public function test_the_manual_request_endpoint_is_gone(): void
    {
        $this->withToken($this->tokenFor($this->affiliate()))
            ->postJson('/api/affiliate/withdrawals', ['amount' => 5000])
            ->assertStatus(405);
    }

    public function test_an_auto_opened_ticket_then_the_existing_admin_approve_flow_debits_the_affiliate_ledger(): void
    {
        $affiliate = $this->affiliate();
        // Earned last month, so it is before this month's cut-off.
        $this->travelTo($this->midLastMonth());
        $this->ledger->credit(LedgerOwnerType::Affiliate, $affiliate->id, 40000, 'order_profit', 'order', 1);
        $this->travelBack();

        // The full path: the monthly payout opens the ticket → admin approve().
        app(AffiliateWithdrawalService::class)->openMonthly($affiliate);

        $withdrawal = Withdrawal::query()->firstOrFail();
        $this->assertNull($withdrawal->requested_by);

        $this->actingAsSteppedUp(AdminUser::factory()->create(['role' => 'super_admin']));

        $this->patchJson("/api/withdrawals/{$withdrawal->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        // The maker-checker "different Super Admin" check compares
        // $admin->id === $withdrawal->requested_by — null for a system
        // ticket, so it never false-positives.
        $this->assertSame(0, $this->ledger->balance(LedgerOwnerType::Affiliate, $affiliate->id));
    }
}
