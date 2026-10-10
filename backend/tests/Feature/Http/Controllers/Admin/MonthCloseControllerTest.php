<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\BudgetEnvelope;
use App\Models\CashAccount;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-083 2026-10-10 addendum, decisions 9–14 — the month close's HTTP
 * surface. The money rules live in `MonthCloseServiceTest`.
 */
class MonthCloseControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function closePayload(int $allocateSen): array
    {
        $rolling = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();

        return [
            'year' => 2026,
            'month' => 9,
            'lines' => [['budget_envelope_id' => $rolling->id, 'amount_sen' => $allocateSen]],
            'cash_balances' => [['cash_account_id' => CashAccount::query()->value('id'), 'balance_sen' => 0]],
            'gap_note' => 'CHIP payout lands in October',
        ];
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/accounting/month-close?year=2026&month=9')->assertForbidden();
        $this->postJson('/api/accounting/month-close', [])->assertForbidden();
    }

    public function test_preview_close_and_reopen(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
        Order::factory()->delivered()->create(['selling_price' => 1200, 'cost_price' => 0, 'paid_at' => '2026-09-10 02:00:00']);

        $this->getJson('/api/accounting/month-close?year=2026&month=9')
            ->assertOk()
            ->assertJsonPath('allocate_sen', 1200)
            ->assertJsonPath('blocked_reason', null)
            ->assertJsonPath('close', null)
            ->assertJsonPath('cash_accounts.0.name', 'Held by Farres (mixed)');

        $this->postJson('/api/accounting/month-close', $this->closePayload(1000))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');

        $close = $this->postJson('/api/accounting/month-close', $this->closePayload(1200))->assertCreated()->json('close');

        $this->getJson('/api/accounting/month-close?year=2026&month=9')
            ->assertJsonPath('close.can_reopen', true)
            ->assertJsonPath('close.gap_note', 'CHIP payout lands in October');

        $this->postJson("/api/accounting/month-close/{$close['id']}/reopen", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/accounting/month-close/{$close['id']}/reopen", ['reason' => 'late refund'])->assertOk();
        $this->getJson('/api/accounting/month-close?year=2026&month=9')->assertJsonPath('close', null);
    }

    public function test_cash_accounts_can_be_added_renamed_and_archived(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());

        $id = $this->postJson('/api/accounting/cash-accounts', ['name' => 'LWF Maybank'])->assertCreated()->json('cash_account.id');
        $this->postJson('/api/accounting/cash-accounts', ['name' => 'LWF Maybank'])->assertUnprocessable();

        $this->patchJson("/api/accounting/cash-accounts/{$id}", ['name' => 'LWF Maybank Current'])->assertOk();
        $this->patchJson("/api/accounting/cash-accounts/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('cash_account.is_active', false);
    }
}
