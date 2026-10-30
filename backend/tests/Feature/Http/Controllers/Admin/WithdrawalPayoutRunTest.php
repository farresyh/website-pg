<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\LedgerEntry;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use App\Services\Withdrawal\WithdrawalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ADR-132 decision 10: the admin worklist for the monthly payout.
 * "Now" is 2026-11-10 10:00 MYT, past the 7th-day window.
 */
class WithdrawalPayoutRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Kuala_Lumpur'));
        $this->actingAsSteppedUp(AdminUser::factory()->create(['role' => 'admin']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function affiliate(string $name, bool $bank = true, int $earned = 25000): Affiliate
    {
        $a = Affiliate::query()->create([
            'business_name' => $name, 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active',
            'bank_name' => $bank ? 'Maybank' : null,
            'bank_account_no' => $bank ? '1' : null,
            'bank_account_holder' => $bank ? $name : null,
        ]);
        app(LedgerService::class)->openAccount('affiliate', $a->id);
        $e = app(LedgerService::class)->credit('affiliate', $a->id, $earned, 'order_profit', 'order', $a->id);
        LedgerEntry::query()->whereKey($e->id)->update(['created_at' => '2026-10-20 00:00:00']);

        return $a;
    }

    private function ticket(Affiliate $a, string $period, WithdrawalStatus $status = WithdrawalStatus::Pending): Withdrawal
    {
        return Withdrawal::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $a->id, 'payout_period' => $period, 'amount' => 5000,
            'bank_name' => 'M', 'bank_account_no' => '1', 'bank_account_holder' => 'A', 'status' => $status,
        ]);
    }

    public function test_index_names_the_affiliate_and_the_period(): void
    {
        $a = $this->affiliate('Acme');
        $this->ticket($a, '2026-11');

        $this->getJson('/api/withdrawals')->assertOk()
            ->assertJsonPath('withdrawals.0.affiliate.name', 'Acme')
            ->assertJsonPath('withdrawals.0.payout_period', '2026-11');
    }

    public function test_payout_run_lists_skipped_affiliates_with_a_reason_and_stale_tickets(): void
    {
        $noBank = $this->affiliate('NoBank', bank: false);
        $this->affiliate('Tiny', earned: 500);
        $blocked = $this->affiliate('Blocked');
        $stale = $this->ticket($blocked, '2026-10'); // Pending from last month
        $this->affiliate('Fine'); // would be opened by the next run, so it is not "skipped"
        $opened = $this->affiliate('Opened');
        $this->ticket($opened, '2026-11', WithdrawalStatus::Approved);

        $res = $this->getJson('/api/withdrawals/payout-run')->assertOk();

        $res->assertJsonPath('period', '2026-11');
        $skipped = collect($res->json('skipped'))->pluck('skip', 'name')->all();
        $this->assertSame(['NoBank' => 'no_bank', 'Tiny' => 'below_min', 'Blocked' => 'open_ticket'], $skipped);
        $this->assertSame([$stale->id], collect($res->json('stale_tickets'))->pluck('id')->all());
        $this->assertSame($noBank->id, $res->json('skipped.0.affiliate_id'));
    }

    public function test_a_pending_ticket_inside_the_window_is_not_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', 'Asia/Kuala_Lumpur'));
        $this->ticket($this->affiliate('Acme'), '2026-11');

        $this->getJson('/api/withdrawals/payout-run')->assertOk()->assertJsonCount(0, 'stale_tickets');
    }
}
