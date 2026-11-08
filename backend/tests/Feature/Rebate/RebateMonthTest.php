<?php

namespace Tests\Feature\Rebate;

use App\Models\AdminUser;
use App\Models\AffiliateUser;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerRebateLadder;
use App\Models\ResellerRebateMilestone;
use App\Models\ResellerRebateMonth;
use App\Models\ResellerTier;
use App\Models\ResellerWalletTierChange;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Rebate\RebateClaimService;
use App\Services\Rebate\UnspentRebate;
use App\Services\Reseller\ResellerClosureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ADR-139 PR 3b-i: month finalise, expiry, claiming finalised rows, unspent rebate and the closure forfeit. */
class RebateMonthTest extends TestCase
{
    use RefreshDatabase;

    private ResellerTier $ss;

    private Reseller $reseller;

    private AffiliateUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        AdminUser::factory()->create(['email' => 'founder@example.com', 'is_active' => true]);
        Carbon::setTestNow(Carbon::parse('2026-12-15 02:00:00', 'UTC'));
        $this->ss = ResellerTier::query()->create(['name' => 'Tier SS', 'markup_percent' => 3, 'is_active' => true, 'sort_order' => 1]);
        $this->reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true, 'reseller_tier_id' => $this->ss->id]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $this->reseller->id);
        ResellerWalletTierChange::query()->create(['reseller_id' => $this->reseller->id, 'to_tier_id' => $this->ss->id, 'effective_on' => '2026-11-01', 'applied_at' => now()]);
        $this->user = AffiliateUser::query()->create([
            'owner_type' => AccountOwnerType::Reseller->value, 'owner_id' => $this->reseller->id,
            'name' => 'Ali', 'email' => 'ali@example.com', 'password' => 'x', 'is_active' => true,
        ]);
        ResellerRebateLadder::query()->create(['effective_from' => '2026-12-01', 'max_share_bp' => 3500, 'steps' => [
            ['threshold_sen' => 50000, 'rewards' => [(string) $this->ss->id => 100]],
            ['threshold_sen' => 150000, 'rewards' => [(string) $this->ss->id => 600]],
            ['threshold_sen' => 300000, 'rewards' => [(string) $this->ss->id => 1700]],
        ]]);
    }

    private function delivered(int $sellingSen, int $profitSen, string $recognisedAtUtc = '2026-12-10 04:00:00'): Order
    {
        return Order::query()->create([
            'placed_via' => 'reseller_api', 'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(), 'customer_email' => 'buyer@example.com', 'player_id' => '1',
            'wallet_reseller_id' => $this->reseller->id,
            'cost_price' => $sellingSen - $profitSen, 'standard_selling_price' => $sellingSen, 'selling_price' => $sellingSen,
            'transaction_fee' => 0, 'final_amount' => $sellingSen, 'platform_profit' => $profitSen, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value,
            'recognised_at' => $recognisedAtUtc,
        ]);
    }

    private function claim(int $step): ResellerRebateMilestone
    {
        return app(RebateClaimService::class)->claim($this->reseller, $this->user, 2026, 12, $step, acceptTerms: true);
    }

    private function balance(): int
    {
        return app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $this->reseller->id);
    }

    private function toJanuary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 16:10:00', 'UTC')); // 1 Jan 2027, 00:10 KL
    }

    private function rows(): Collection
    {
        return ResellerRebateMilestone::query()->orderBy('kind')->orderBy('step')->get();
    }

    public function test_finalise_writes_each_unclaimed_reached_step_once_and_records_the_month(): void
    {
        $this->delivered(160000, 4640);
        $this->claim(1);
        $this->toJanuary();

        $this->artisan('app:finalize-rebate-months')->assertSuccessful();
        $this->artisan('app:finalize-rebate-months')->assertSuccessful();

        $step2 = ResellerRebateMilestone::query()->where('step', 2)->sole();
        $this->assertSame([ResellerRebateMilestone::AVAILABLE, 500, false], [$step2->state, $step2->amount_sen, $step2->adjusted]);
        $this->assertSame(2, ResellerRebateMilestone::query()->count());
        $this->assertSame(1, ResellerRebateMonth::query()->count());
        Http::assertNothingSent();

        // Claiming a finalised row pays its stored amount.
        $this->claim(2);
        $this->assertSame(600, $this->balance());
    }

    /** Stress test F1: an early cut that no later claim healed becomes the month's adjustment row. */
    public function test_a_shortfall_no_later_claim_made_up_becomes_an_adjustment_and_alerts(): void
    {
        $this->delivered(60000, 200); // cap 70: step 1 pays 70 of 100
        $this->assertSame(70, $this->claim(1)->amount_sen);
        $this->delivered(10000, 2000, '2026-12-20 04:00:00'); // profit 2,200 → cap 770
        $this->toJanuary();

        $this->artisan('app:finalize-rebate-months')->assertSuccessful();

        $adjustment = ResellerRebateMilestone::query()->where('kind', ResellerRebateMilestone::KIND_ADJUSTMENT)->sole();
        $this->assertSame(30, $adjustment->amount_sen);
        Http::assertSentCount(1);

        app(RebateClaimService::class)->claim($this->reseller, $this->user, 2026, 12, 0);
        $this->assertSame(100, $this->balance());
    }

    public function test_an_overpayment_after_a_negative_order_is_alerted_never_clawed_back(): void
    {
        $this->delivered(60000, 1740);
        $this->claim(1);
        $this->delivered(1000, -1500, '2026-12-20 04:00:00'); // profit 240 → cap 84 < paid 100
        $this->toJanuary();

        $this->artisan('app:finalize-rebate-months')->assertSuccessful();

        $this->assertSame(100, $this->balance());
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'Not clawed back'));
    }

    public function test_an_unclaimed_reward_expires_at_its_deadline_dated_at_the_deadline(): void
    {
        $this->delivered(60000, 1740);
        $this->toJanuary();
        $this->artisan('app:finalize-rebate-months')->assertSuccessful();

        Carbon::setTestNow(Carbon::parse('2027-01-31 15:59:00', 'UTC')); // 31 Jan 23:59 KL
        $this->artisan('app:expire-rebate-rewards')->assertSuccessful();
        $this->assertSame(ResellerRebateMilestone::AVAILABLE, $this->rows()->first()->state);

        Carbon::setTestNow(Carbon::parse('2027-02-02 03:00:00', 'UTC')); // a late run
        $this->artisan('app:expire-rebate-rewards')->assertSuccessful();

        $row = $this->rows()->first();
        $this->assertSame(ResellerRebateMilestone::EXPIRED, $row->state);
        $this->assertSame('2027-01-31 16:00:00', $row->expired_at->utc()->toDateTimeString());
    }

    public function test_unspent_rebate_is_spent_first_and_restored_by_a_refund(): void
    {
        $ledger = app(LedgerService::class);
        $id = $this->reseller->id;
        $ledger->credit(LedgerOwnerType::ResellerWallet, $id, 1000, 'wallet_topup');
        $ledger->credit(LedgerOwnerType::ResellerWallet, $id, 300, 'wallet_rebate', 'rebate_milestone', 1);
        $ledger->debit(LedgerOwnerType::ResellerWallet, $id, 200, 'wallet_debit', 'order', 11);
        $this->assertSame(100, app(UnspentRebate::class)->forReseller($id));

        $ledger->credit(LedgerOwnerType::ResellerWallet, $id, 200, 'wallet_refund', 'order', 11);
        $this->assertSame(300, app(UnspentRebate::class)->forReseller($id));

        $ledger->debit(LedgerOwnerType::ResellerWallet, $id, 500, 'wallet_debit', 'order', 12);
        $this->assertSame(0, app(UnspentRebate::class)->forReseller($id));
    }

    public function test_an_unknown_wallet_entry_type_fails_closed(): void
    {
        LedgerEntry::query()->create(['owner_type' => 'reseller_wallet', 'owner_id' => $this->reseller->id, 'type' => 'wallet_mystery', 'amount' => 100]);

        $this->expectException(\ValueError::class);
        app(UnspentRebate::class)->forReseller($this->reseller->id);
    }

    public function test_closure_forfeits_unspent_rebate_refunds_only_the_rest_and_lapses_unclaimed_rewards(): void
    {
        $ledger = app(LedgerService::class);
        $ledger->credit(LedgerOwnerType::ResellerWallet, $this->reseller->id, 5000, 'wallet_topup');
        $this->delivered(160000, 4640);
        $this->claim(1); // +100 rebate credit
        $this->toJanuary();
        $this->artisan('app:finalize-rebate-months')->assertSuccessful(); // step 2 available, unclaimed
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());

        $this->getJson("/api/resellers/{$this->reseller->id}/close-check")
            ->assertOk()
            ->assertJsonPath('balance_sen', 5100)
            ->assertJsonPath('unspent_rebate_sen', 100)
            ->assertJsonPath('refund_sen', 5000);

        $refunded = app(ResellerClosureService::class)->close($this->reseller, AdminUser::query()->first()->id, now('Asia/Kuala_Lumpur')->toDateString(), 'BANK-1');

        $this->assertSame(5000, $refunded);
        $this->assertDatabaseHas('ledger_entries', ['type' => 'wallet_rebate_forfeit', 'amount' => -100]);
        $this->assertDatabaseHas('ledger_entries', ['type' => 'wallet_closure_refund', 'amount' => -5000]);
        $this->assertSame(0, $this->balance());
        $this->assertSame(ResellerRebateMilestone::EXPIRED, ResellerRebateMilestone::query()->where('step', 2)->sole()->state);
    }
}
