<?php

namespace Tests\Concurrency;

use App\Models\Affiliate;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use App\Models\LedgerEntry;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-056 (grilled 2026-08-30) decision 6 / consequence note: proves the
 * affiliate tier-fee charge is race-safe. A double-cron, or a manual admin
 * charge racing the scheduled one, must never debit the fee twice from a
 * affiliate's earnings. Two genuinely separate PHP processes each call
 * AffiliateTierFeeService::chargeCycle() on the same subscription with
 * earnings funded for exactly ONE fee — only one debit may land.
 *
 * Item 33 (2026-09-26 audit) update: the loser used to fall through to a
 * failed debit (insufficient balance, now zero) and incorrectly transition
 * the subscription to `grace` — even though this cycle's fee genuinely
 * was already collected by the winner. `chargeCycle()` now recognizes an
 * already-completed charge for the current cycle before attempting to
 * debit again, so the loser now correctly no-ops as `active` instead.
 *

 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class AffiliateTierFeeConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_simultaneous_tier_fee_charges_debit_the_fee_only_once(): void
    {
        $ledger = app(LedgerService::class);

        $affiliate = Affiliate::query()->create([
            'business_name' => 'Concurrency Affiliate',
            'markup_pct' => 10,
            'status' => 'active',
        ]);

        $tier = AffiliateMembershipTier::query()->create([
            'name' => 'Silver',
            'monthly_fee_sen' => 5000,
            'markup_percent' => 5.0,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $subscription = AffiliateSubscription::query()->create([
            'affiliate_id' => $affiliate->id,
            'affiliate_membership_tier_id' => $tier->id,
            'status' => AffiliateSubscriptionStatus::Active,
            'current_period_started_at' => now()->subDays(30),
            'next_charge_at' => now()->subMinute(),
        ]);

        // Fund exactly one fee — a second successful debit would overdraw.
        $ledger->openAccount('affiliate', $affiliate->id);
        $ledger->credit('affiliate', $affiliate->id, 5000, 'order_profit', 'order', 1);

        $resultFileA = tempnam(sys_get_temp_dir(), 'affiliate_fee_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'affiliate_fee_');

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
        ];

        $command = fn (string $resultFile) => [
            PHP_BINARY, 'artisan', 'app:affiliate-test-charge-tier-fee', (string) $subscription->id, $resultFile,
        ];

        $processA = Process::path(base_path())->env($env)->start($command($resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command($resultFileB));

        $processA->wait();
        $processB->wait();

        $outcomes = [file_get_contents($resultFileA), file_get_contents($resultFileB)];

        unlink($resultFileA);
        unlink($resultFileB);

        // Exactly one debit landed; balance is zero, not negative.
        $this->assertSame(0, $ledger->balance('affiliate', $affiliate->id));
        $this->assertSame(
            1,
            LedgerEntry::query()
                ->where('owner_type', 'affiliate')
                ->where('owner_id', $affiliate->id)
                ->where('type', 'affiliate_tier_fee')
                ->count(),
            'Expected exactly one affiliate_tier_fee ledger entry, got outcomes: '.json_encode($outcomes),
        );

        // Both processes report active: one landed the real debit, the
        // other recognized the cycle was already charged and no-op'd
        // rather than failing into grace over a duplicate attempt.
        $this->assertSame(['active', 'active'], $outcomes, 'Unexpected outcome pair: '.json_encode($outcomes));
    }

    /**
     * Item 33 (2026-09-26 audit): the above test can't actually distinguish
     * "the race is serialized correctly" from "the affiliate simply didn't
     * have enough money for a second debit" — funding exactly one fee
     * means LedgerService::debit()'s own balance check would reject a
     * second charge either way. This test funds TWO fees' worth of
     * earnings, so a second debit is financially possible, and proves
     * chargeCycle() itself — not just the ledger balance — refuses to
     * charge the same cycle twice: a call that unblocks onto an
     * already-charged row (status Active, `next_charge_at` freshly
     * advanced) must no-op rather than debit again.
     */
    public function test_two_simultaneous_tier_fee_charges_never_double_charge_even_when_funds_allow_it(): void
    {
        $ledger = app(LedgerService::class);

        $affiliate = Affiliate::query()->create([
            'business_name' => 'Overfunded Affiliate',
            'markup_pct' => 10,
            'status' => 'active',
        ]);

        $tier = AffiliateMembershipTier::query()->create([
            'name' => 'Gold',
            'monthly_fee_sen' => 5000,
            'markup_percent' => 5.0,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $subscription = AffiliateSubscription::query()->create([
            'affiliate_id' => $affiliate->id,
            'affiliate_membership_tier_id' => $tier->id,
            'status' => AffiliateSubscriptionStatus::Active,
            'current_period_started_at' => now()->subDays(30),
            'next_charge_at' => now()->subMinute(),
        ]);

        // Funded for TWO fees — a second debit is financially possible if
        // chargeCycle() doesn't itself refuse to re-charge an already-paid cycle.
        $ledger->openAccount('affiliate', $affiliate->id);
        $ledger->credit('affiliate', $affiliate->id, 10_000, 'order_profit', 'order', 1);

        $resultFileA = tempnam(sys_get_temp_dir(), 'affiliate_fee_overfunded_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'affiliate_fee_overfunded_');

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
        ];

        $command = fn (string $resultFile) => [
            PHP_BINARY, 'artisan', 'app:affiliate-test-charge-tier-fee', (string) $subscription->id, $resultFile,
        ];

        $processA = Process::path(base_path())->env($env)->start($command($resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command($resultFileB));

        $processA->wait();
        $processB->wait();

        $outcomes = [file_get_contents($resultFileA), file_get_contents($resultFileB)];

        unlink($resultFileA);
        unlink($resultFileB);

        // Exactly one debit landed — 5000 left over, not 0.
        $this->assertSame(5000, $ledger->balance('affiliate', $affiliate->id));
        $this->assertSame(
            1,
            LedgerEntry::query()
                ->where('owner_type', 'affiliate')
                ->where('owner_id', $affiliate->id)
                ->where('type', 'affiliate_tier_fee')
                ->count(),
            'Expected exactly one affiliate_tier_fee ledger entry despite sufficient funds for two, got outcomes: '.json_encode($outcomes),
        );

        // Both processes see Active — the second one's no-op still
        // reflects the (already-updated) Active status, not Grace.
        $this->assertSame(['active', 'active'], $outcomes, 'Unexpected outcome pair: '.json_encode($outcomes));
    }
}
