<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use App\Services\Withdrawal\WithdrawalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WithdrawalControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fundPlatformLedger(int $amountSen): void
    {
        app(LedgerService::class)->openAccount('platform', null);
        app(LedgerService::class)->credit('platform', null, $amountSen, 'adjustment');
    }

    /**
     * ADR-083 2026-10-10 addendum, decision 17: the platform owner no longer
     * withdraws here. Money leaving to a director is an Envelope Ledger
     * repayment or dividend; this screen only reviews affiliate requests.
     */
    public function test_the_platform_owner_can_no_longer_request_a_withdrawal(): void
    {
        $this->fundPlatformLedger(50_000);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->postJson('/api/withdrawals', ['amount' => 10_000])->assertMethodNotAllowed();
        $this->assertDatabaseCount('withdrawals', 0);
    }

    public function test_index_returns_stats_without_a_platform_balance(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        Withdrawal::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => 1, 'amount' => 5_000,
            'bank_name' => 'Maybank', 'bank_account_no' => '111', 'bank_account_holder' => 'Acme',
            'status' => WithdrawalStatus::Pending,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/withdrawals');

        $response->assertOk();
        $response->assertJsonMissingPath('available_balance');
        $response->assertJsonPath('stats.pending.count', 1);
        $response->assertJsonPath('stats.pending.total', 5_000);
    }

    public function test_below_threshold_the_same_admin_can_request_and_approve(): void
    {
        $this->fundPlatformLedger(50_000);
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'platform',
            'amount' => 10_000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'KRS',
            'status' => WithdrawalStatus::Pending,
            'requested_by' => $admin->id,
        ]);

        $response = $this->patchJson("/api/withdrawals/{$withdrawal->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('status', 'approved');
        $this->assertSame(40_000, app(LedgerService::class)->balance('platform', null));
    }

    public function test_at_threshold_a_regular_admin_cannot_approve(): void
    {
        $this->fundPlatformLedger(500_000);
        $requester = AdminUser::factory()->create(['role' => 'admin']);
        $approver = AdminUser::factory()->create(['role' => 'admin']);

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'platform',
            'amount' => 200_000, // == configured threshold
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'KRS',
            'status' => WithdrawalStatus::Pending,
            'requested_by' => $requester->id,
        ]);

        Sanctum::actingAs($approver);
        $response = $this->patchJson("/api/withdrawals/{$withdrawal->id}/approve");

        $response->assertUnprocessable();
    }

    public function test_at_threshold_the_same_super_admin_cannot_approve_their_own_request(): void
    {
        $this->fundPlatformLedger(500_000);
        $requester = AdminUser::factory()->superAdmin()->create();

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'platform',
            'amount' => 200_000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'KRS',
            'status' => WithdrawalStatus::Pending,
            'requested_by' => $requester->id,
        ]);

        Sanctum::actingAs($requester);
        $response = $this->patchJson("/api/withdrawals/{$withdrawal->id}/approve");

        $response->assertUnprocessable();
    }

    public function test_at_threshold_a_different_super_admin_can_approve(): void
    {
        $this->fundPlatformLedger(500_000);
        $requester = AdminUser::factory()->superAdmin()->create();
        $approver = AdminUser::factory()->superAdmin()->create();

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'platform',
            'amount' => 200_000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'KRS',
            'status' => WithdrawalStatus::Pending,
            'requested_by' => $requester->id,
        ]);

        Sanctum::actingAs($approver);
        $response = $this->patchJson("/api/withdrawals/{$withdrawal->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('status', 'approved');
    }

    public function test_cannot_approve_a_non_pending_withdrawal(): void
    {
        $this->fundPlatformLedger(50_000);
        $admin = AdminUser::factory()->create(['role' => 'admin']);

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'platform',
            'amount' => 10_000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'KRS',
            'status' => WithdrawalStatus::Rejected,
            'requested_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->patchJson("/api/withdrawals/{$withdrawal->id}/approve");

        $response->assertUnprocessable();
    }

    public function test_admin_can_reject_a_pending_withdrawal(): void
    {
        $this->fundPlatformLedger(50_000);
        $admin = AdminUser::factory()->create(['role' => 'admin']);

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'platform',
            'amount' => 10_000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'KRS',
            'status' => WithdrawalStatus::Pending,
            'requested_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->patchJson("/api/withdrawals/{$withdrawal->id}/reject", ['admin_note' => 'Wrong bank details']);

        $response->assertOk();
        $response->assertJsonPath('status', 'rejected');
        $this->assertSame(50_000, app(LedgerService::class)->balance('platform', null));
    }

    public function test_cannot_reject_an_approved_withdrawal(): void
    {
        $this->fundPlatformLedger(50_000);
        $admin = AdminUser::factory()->create(['role' => 'admin']);

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'platform',
            'amount' => 10_000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'KRS',
            'status' => WithdrawalStatus::Approved,
            'requested_by' => $admin->id,
            'approved_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->patchJson("/api/withdrawals/{$withdrawal->id}/reject");

        $response->assertUnprocessable();
    }

    public function test_admin_can_complete_an_approved_withdrawal(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'platform',
            'amount' => 10_000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'KRS',
            'status' => WithdrawalStatus::Approved,
            'requested_by' => $admin->id,
            'approved_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->patchJson("/api/withdrawals/{$withdrawal->id}/complete");

        $response->assertOk();
        $response->assertJsonPath('status', 'completed');
        $this->assertNotNull($withdrawal->fresh()->processed_at);
    }

    public function test_cannot_complete_a_pending_withdrawal(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'platform',
            'amount' => 10_000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'KRS',
            'status' => WithdrawalStatus::Pending,
            'requested_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->patchJson("/api/withdrawals/{$withdrawal->id}/complete");

        $response->assertUnprocessable();
    }

    public function test_index_flags_an_affiliate_withdrawal_whose_bank_details_changed_since_last_approval(): void
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active',
        ]);

        $first = Withdrawal::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $affiliate->id, 'amount' => 10_000,
            'bank_name' => 'Maybank', 'bank_account_no' => '111', 'bank_account_holder' => 'Acme',
            'status' => WithdrawalStatus::Approved, 'approved_by' => $admin->id,
        ]);
        $second = Withdrawal::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $affiliate->id, 'amount' => 5_000,
            'bank_name' => 'CIMB', 'bank_account_no' => '999', 'bank_account_holder' => 'Someone Else',
            'status' => WithdrawalStatus::Pending,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/withdrawals');

        $response->assertOk();
        $byId = collect($response->json('withdrawals'))->keyBy('id');
        $this->assertNull($byId[$first->id]['bank_details_changed_since_last_approval']);
        $this->assertTrue($byId[$second->id]['bank_details_changed_since_last_approval']);
    }

    public function test_index_does_not_flag_an_affiliate_withdrawal_with_unchanged_bank_details(): void
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active',
        ]);

        Withdrawal::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $affiliate->id, 'amount' => 10_000,
            'bank_name' => 'Maybank', 'bank_account_no' => '111', 'bank_account_holder' => 'Acme',
            'status' => WithdrawalStatus::Completed, 'approved_by' => $admin->id, 'processed_at' => now(),
        ]);
        $second = Withdrawal::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $affiliate->id, 'amount' => 5_000,
            'bank_name' => 'Maybank', 'bank_account_no' => '111', 'bank_account_holder' => 'Acme',
            'status' => WithdrawalStatus::Pending,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/withdrawals');

        $response->assertOk();
        $byId = collect($response->json('withdrawals'))->keyBy('id');
        $this->assertFalse($byId[$second->id]['bank_details_changed_since_last_approval']);
    }

    public function test_index_never_flags_a_platform_withdrawal(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        Withdrawal::query()->create([
            'owner_type' => 'platform', 'amount' => 5_000, 'bank_name' => 'Maybank', 'bank_account_no' => '123',
            'bank_account_holder' => 'KRS', 'status' => WithdrawalStatus::Pending, 'requested_by' => $admin->id,
        ]);

        $response = $this->getJson('/api/withdrawals');

        $response->assertOk();
        $this->assertNull($response->json('withdrawals.0.bank_details_changed_since_last_approval'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/withdrawals');

        $response->assertUnauthorized();
    }
}
