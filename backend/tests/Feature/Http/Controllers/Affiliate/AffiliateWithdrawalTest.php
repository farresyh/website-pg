<?php

namespace Tests\Feature\Http\Controllers\Affiliate;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateUser;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-059 59c: the affiliate-portal side of WTH-1..5 — request only.
 * Approval / rejection / completion stay on the admin flow, unchanged.
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
            'affiliate_id' => $affiliate->id,
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

    public function test_store_creates_a_affiliate_owned_pending_withdrawal_prefilled_from_profile(): void
    {
        $affiliate = $this->affiliate();
        $this->ledger->credit(LedgerOwnerType::Affiliate, $affiliate->id, 30000, 'order_profit', 'order', 1);

        $this->withToken($this->tokenFor($affiliate))->postJson('/api/affiliate/withdrawals', [
            'amount' => 25000,
        ])->assertCreated()->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('withdrawals', [
            'owner_type' => 'affiliate',
            'owner_id' => $affiliate->id,
            'amount' => 25000,
            'bank_name' => 'Maybank',
            'requested_by' => null,
        ]);
    }

    public function test_store_rejects_an_amount_over_the_withdrawable_balance(): void
    {
        $affiliate = $this->affiliate();
        $this->ledger->credit(LedgerOwnerType::Affiliate, $affiliate->id, 5000, 'order_profit', 'order', 1);

        $this->withToken($this->tokenFor($affiliate))->postJson('/api/affiliate/withdrawals', [
            'amount' => 9000,
        ])->assertStatus(422)->assertJsonValidationErrorFor('amount');

        $this->assertDatabaseCount('withdrawals', 0);
    }

    public function test_store_422s_when_no_bank_details_exist_anywhere(): void
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'NoBank', 'markup_pct' => 0, 'status' => 'active',
        ]);
        $this->ledger->credit(LedgerOwnerType::Affiliate, $affiliate->id, 10000, 'order_profit', 'order', 1);

        $this->withToken($this->tokenFor($affiliate))->postJson('/api/affiliate/withdrawals', [
            'amount' => 5000,
        ])->assertStatus(422)->assertJsonValidationErrorFor('bank_name');
    }

    public function test_store_blocks_a_second_request_while_one_is_in_progress(): void
    {
        $affiliate = $this->affiliate();
        $this->ledger->credit(LedgerOwnerType::Affiliate, $affiliate->id, 50000, 'order_profit', 'order', 1);
        $token = $this->tokenFor($affiliate);

        $this->withToken($token)->postJson('/api/affiliate/withdrawals', ['amount' => 10000])->assertCreated();
        $this->withToken($token)->postJson('/api/affiliate/withdrawals', ['amount' => 10000])
            ->assertStatus(422)->assertJsonValidationErrorFor('amount');

        $this->assertDatabaseCount('withdrawals', 1);
    }

    public function test_a_portal_request_then_the_existing_admin_approve_flow_debits_the_affiliate_ledger(): void
    {
        $affiliate = $this->affiliate();
        $this->ledger->credit(LedgerOwnerType::Affiliate, $affiliate->id, 40000, 'order_profit', 'order', 1);

        // The full path: portal store() (which also opens the
        // ledger_accounts row) → admin approve().
        $this->withToken($this->tokenFor($affiliate))
            ->postJson('/api/affiliate/withdrawals', ['amount' => 15000])
            ->assertCreated();

        $withdrawal = Withdrawal::query()->firstOrFail();
        $this->assertNull($withdrawal->requested_by);

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));

        $this->patchJson("/api/withdrawals/{$withdrawal->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        // The maker-checker "different Super Admin" check compares
        // $admin->id === $withdrawal->requested_by — null for an affiliate
        // request, so it never false-positives.
        $this->assertSame(25000, $this->ledger->balance(LedgerOwnerType::Affiliate, $affiliate->id));
    }
}
