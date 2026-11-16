<?php

namespace Tests\Feature\Reseller;

use App\Models\AdminUser;
use App\Models\CustomerNotification;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Models\ResellerDormancyNotice;
use App\Models\ResellerStatusChange;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerAccountStatus;
use App\Services\Reseller\ResellerDeactivationReason;
use App\Services\Reseller\ResellerDormancyService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ResellerBotFixtures;
use Tests\TestCase;

/**
 * ADR-142: warn on day (period − lead), deactivate on day period, only after
 * a warning for the same idle spell went out at least the lead earlier.
 * Day 0 is 2 Nov 2026 (Malaysia); the job runs at 10:00 MYT (02:00 UTC).
 */
class ResellerDormancyTest extends TestCase
{
    use RefreshDatabase;
    use ResellerBotFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureReplies();
        Http::fake(['*useplunk.com/*' => Http::response(['success' => true])]);
        AdminUser::factory()->create(['email' => 'founder@example.com', 'is_active' => true]);
        $this->travelTo(self::day(0));
    }

    private static function day(int $n): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-02 02:00:00', 'UTC')->addDays($n);
    }

    private function enable(int $days = 7, int $lead = 3, ?CarbonImmutable $enabledAt = null): void
    {
        PlatformSettings::current()->update([
            'reseller_dormancy_enabled' => true,
            'reseller_dormancy_enabled_at' => $enabledAt ?? self::day(-30),
            'reseller_dormancy_days' => $days,
            'reseller_dormancy_warning_days' => $lead,
            'reseller_support_whatsapp' => '+60123456789',
        ]);
    }

    /** @return array{warned: list<Reseller>, deactivated: list<Reseller>} */
    private function runOn(int $day): array
    {
        $this->travelTo(self::day($day));

        return app(ResellerDormancyService::class)->run();
    }

    private function notices(Reseller $r, string $kind): int
    {
        return ResellerDormancyNotice::query()->where('reseller_id', $r->id)->where('kind', $kind)->count();
    }

    /** @return list<string> */
    private function emailedTo(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'useplunk.com'))
            ->map(fn (Request $r) => $r['to'])->values()->all();
    }

    public function test_nothing_happens_while_the_switch_is_off(): void
    {
        $reseller = $this->makeLinkedReseller();

        $this->runOn(30);

        $this->assertTrue($reseller->fresh()->is_active);
        $this->assertSame(0, ResellerDormancyNotice::query()->count());
    }

    public function test_warned_on_day_four_once_then_deactivated_on_day_seven(): void
    {
        $this->enable();
        $reseller = $this->makeLinkedReseller();
        $reseller->update(['email' => 'acme@example.com']);

        $this->assertSame([], $this->runOn(3)['warned']);
        $this->assertCount(1, $this->runOn(4)['warned']);
        $this->assertSame([], $this->runOn(4)['warned'], 'a rerun the same day sends nothing twice');
        $this->assertSame(1, $this->notices($reseller, ResellerDormancyNotice::WARNING));
        $this->assertSame(1, CustomerNotification::query()->where('event', 'reseller_dormancy_warning')->count());
        $this->assertContains('acme@example.com', $this->emailedTo());

        $this->assertSame([], $this->runOn(6)['deactivated']);
        $this->assertCount(1, $this->runOn(7)['deactivated']);

        $fresh = $reseller->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame(ResellerDeactivationReason::Dormant, $fresh->deactivation_reason);
        $change = ResellerStatusChange::query()->sole();
        $this->assertFalse($change->is_active);
        $this->assertNull($change->admin_user_id);
        $this->assertSame(1, $this->notices($reseller, ResellerDormancyNotice::DEACTIVATED));
        $this->assertStringContainsString('+60123456789', CustomerNotification::query()->where('event', 'reseller_dormancy_deactivated')->sole()->message);
        $this->assertContains('founder@example.com', $this->emailedTo());
    }

    /** Decision 21: a missed warning day never means a deactivation without a warning. */
    public function test_a_missed_warning_is_sent_late_and_the_deactivation_waits_the_full_lead(): void
    {
        $this->enable();
        $reseller = $this->makeLinkedReseller();

        $this->assertCount(1, $this->runOn(9)['warned']);
        $this->assertTrue($reseller->fresh()->is_active);
        $this->assertSame([], $this->runOn(11)['deactivated']);
        $this->assertCount(1, $this->runOn(12)['deactivated']);
    }

    /** Decision 1: an order or added balance starts a new idle spell. */
    public function test_a_transaction_after_the_warning_keeps_the_account_on(): void
    {
        $this->enable();
        $reseller = $this->makeLinkedReseller();
        $this->runOn(4);

        $this->travelTo(self::day(5));
        app(LedgerService::class)->debit(LedgerOwnerType::ResellerWallet, $reseller->id, 100, 'wallet_debit');

        $this->assertSame([], $this->runOn(7)['deactivated']);
        $this->assertTrue($reseller->fresh()->is_active);
        $this->assertCount(1, $this->runOn(9)['warned'], 'the new spell gets its own warning');
    }

    public function test_a_refund_is_not_a_transaction(): void
    {
        $this->enable();
        $reseller = $this->makeLinkedReseller();
        $this->runOn(4);

        $this->travelTo(self::day(5));
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 100, 'wallet_refund');

        $this->assertCount(1, $this->runOn(7)['deactivated']);
    }

    public function test_internal_accounts_are_never_deactivated(): void
    {
        $this->enable();
        $reseller = $this->makeLinkedReseller();
        $reseller->update(['internal_reason' => 'Founder test account']);

        $this->runOn(4);
        $this->runOn(30);

        $this->assertTrue($reseller->fresh()->is_active);
        $this->assertSame(0, ResellerDormancyNotice::query()->count());
    }

    /** Decision 22: money may still arrive through a pending top-up. */
    public function test_a_pending_top_up_defers_the_deactivation(): void
    {
        $this->enable();
        $reseller = $this->makeLinkedReseller();
        $this->runOn(4);
        $attempt = WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id, 'reference' => 'WT-DORMANT0001', 'amount_sen' => 5000, 'total_charged_sen' => 5100,
            'channel_code' => 'fpx', 'gateway' => 'chip', 'status' => WalletTopupAttemptStatus::Pending, 'expires_at' => now()->addHour(),
        ]);

        $this->assertSame([], $this->runOn(7)['deactivated']);

        $attempt->update(['status' => WalletTopupAttemptStatus::Expired]);
        $this->assertCount(1, $this->runOn(8)['deactivated']);
    }

    /** Decision 8: reactivation gives a full period again. */
    public function test_reactivation_restarts_the_clock(): void
    {
        $this->enable();
        $reseller = $this->makeLinkedReseller();
        $this->runOn(4);
        $this->runOn(7);

        $this->travelTo(self::day(10));
        app(ResellerAccountStatus::class)->reactivate($reseller, AdminUser::query()->sole()->id);

        $this->assertSame([], $this->runOn(13)['warned']);
        $this->assertCount(1, $this->runOn(14)['warned']);
    }

    /** Decision 7: the day the switch goes on starts everyone's clock, so no one is deactivated for days before. */
    public function test_an_account_idle_before_the_switch_went_on_is_warned_first(): void
    {
        $reseller = $this->makeLinkedReseller();
        $this->enable(enabledAt: self::day(20));

        $this->assertSame([], $this->runOn(20)['warned']);
        $this->assertSame([], $this->runOn(23)['warned']);
        $this->assertCount(1, $this->runOn(24)['warned']);
        $this->assertTrue($reseller->fresh()->is_active);
    }

    public function test_no_admin_email_on_a_quiet_day(): void
    {
        $this->enable();
        $this->makeLinkedReseller();

        $this->runOn(2);

        $this->assertSame([], $this->emailedTo());
    }
}
