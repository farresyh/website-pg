<?php

namespace Tests\Feature\Http\Controllers\Reseller;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-059 59c: the reseller-portal side of WTH-1..5 — request only.
 * Approval / rejection / completion stay on the admin flow, unchanged.
 */
class ResellerWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
    }

    private function reseller(string $name = 'Acme'): Reseller
    {
        return Reseller::query()->create([
            'business_name' => $name,
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567',
            'bank_account_holder' => "{$name} Sdn Bhd",
        ]);
    }

    private function tokenFor(Reseller $reseller): string
    {
        $user = ResellerUser::query()->create([
            'reseller_id' => $reseller->id,
            'name' => 'Staff',
            'email' => 'staff+'.$reseller->id.'@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return $user->createToken('reseller')->plainTextToken;
    }

    public function test_index_returns_balance_prefill_and_only_this_resellers_withdrawals(): void
    {
        $mine = $this->reseller('Mine');
        $other = $this->reseller('Other');
        $this->ledger->credit(LedgerOwnerType::Reseller, $mine->id, 50000, 'order_profit', 'order', 1);

        Withdrawal::query()->create([
            'owner_type' => 'reseller', 'owner_id' => $mine->id, 'amount' => 10000,
            'bank_name' => 'Maybank', 'bank_account_no' => '1', 'bank_account_holder' => 'Mine',
            'status' => 'pending', 'requested_by' => null,
        ]);
        Withdrawal::query()->create([
            'owner_type' => 'reseller', 'owner_id' => $other->id, 'amount' => 20000,
            'bank_name' => 'CIMB', 'bank_account_no' => '2', 'bank_account_holder' => 'Other',
            'status' => 'pending', 'requested_by' => null,
        ]);

        $this->withToken($this->tokenFor($mine))->getJson('/api/reseller/withdrawals')
            ->assertOk()
            ->assertJsonPath('balance', 50000)
            ->assertJsonPath('prefill.bank_name', 'Maybank')
            ->assertJsonCount(1, 'withdrawals');
    }

    public function test_store_creates_a_reseller_owned_pending_withdrawal_prefilled_from_profile(): void
    {
        $reseller = $this->reseller();
        $this->ledger->credit(LedgerOwnerType::Reseller, $reseller->id, 30000, 'order_profit', 'order', 1);

        $this->withToken($this->tokenFor($reseller))->postJson('/api/reseller/withdrawals', [
            'amount' => 25000,
        ])->assertCreated()->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('withdrawals', [
            'owner_type' => 'reseller',
            'owner_id' => $reseller->id,
            'amount' => 25000,
            'bank_name' => 'Maybank',
            'requested_by' => null,
        ]);
    }

    public function test_store_rejects_an_amount_over_the_withdrawable_balance(): void
    {
        $reseller = $this->reseller();
        $this->ledger->credit(LedgerOwnerType::Reseller, $reseller->id, 5000, 'order_profit', 'order', 1);

        $this->withToken($this->tokenFor($reseller))->postJson('/api/reseller/withdrawals', [
            'amount' => 9000,
        ])->assertStatus(422)->assertJsonValidationErrorFor('amount');

        $this->assertDatabaseCount('withdrawals', 0);
    }

    public function test_store_422s_when_no_bank_details_exist_anywhere(): void
    {
        $reseller = Reseller::query()->create([
            'business_name' => 'NoBank', 'markup_pct' => 0, 'status' => 'active',
        ]);
        $this->ledger->credit(LedgerOwnerType::Reseller, $reseller->id, 10000, 'order_profit', 'order', 1);

        $this->withToken($this->tokenFor($reseller))->postJson('/api/reseller/withdrawals', [
            'amount' => 5000,
        ])->assertStatus(422)->assertJsonValidationErrorFor('bank_name');
    }

    public function test_store_blocks_a_second_request_while_one_is_in_progress(): void
    {
        $reseller = $this->reseller();
        $this->ledger->credit(LedgerOwnerType::Reseller, $reseller->id, 50000, 'order_profit', 'order', 1);
        $token = $this->tokenFor($reseller);

        $this->withToken($token)->postJson('/api/reseller/withdrawals', ['amount' => 10000])->assertCreated();
        $this->withToken($token)->postJson('/api/reseller/withdrawals', ['amount' => 10000])
            ->assertStatus(422)->assertJsonValidationErrorFor('amount');

        $this->assertDatabaseCount('withdrawals', 1);
    }

    public function test_a_portal_request_then_the_existing_admin_approve_flow_debits_the_reseller_ledger(): void
    {
        $reseller = $this->reseller();
        $this->ledger->credit(LedgerOwnerType::Reseller, $reseller->id, 40000, 'order_profit', 'order', 1);

        // The full path: portal store() (which also opens the
        // ledger_accounts row) → admin approve().
        $this->withToken($this->tokenFor($reseller))
            ->postJson('/api/reseller/withdrawals', ['amount' => 15000])
            ->assertCreated();

        $withdrawal = Withdrawal::query()->firstOrFail();
        $this->assertNull($withdrawal->requested_by);

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));

        $this->patchJson("/api/withdrawals/{$withdrawal->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        // The maker-checker "different Super Admin" check compares
        // $admin->id === $withdrawal->requested_by — null for a reseller
        // request, so it never false-positives.
        $this->assertSame(25000, $this->ledger->balance(LedgerOwnerType::Reseller, $reseller->id));
    }
}
