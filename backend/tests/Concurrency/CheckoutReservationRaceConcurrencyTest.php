<?php

namespace Tests\Concurrency;

use App\Models\Game;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipQuotaDebit;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-024 2026-10-04 addendum (pre-launch money audit #3): two genuinely
 * separate processes race a partial-cover checkout against one voucher,
 * or a member-priced checkout against one member quota. The money
 * invariant: no order that got a payment link is missing its
 * reservation, and the balance is spent at most once. Whether the two
 * processes overlap is up to the scheduler, so a loser may lose at the
 * reservation (failed closed) or already at pricing — both are fine.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class CheckoutReservationRaceConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_partial_cover_checkouts_never_both_get_a_link_on_one_voucher(): void
    {
        ['game' => $game, 'package' => $package] = $this->catalog();
        $voucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-PARTIAL-RACE',
            'customer_email' => 'buyer@example.com',
            'amount' => 200,
            'remaining' => 200,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $outcomes = $this->race($game, $package, 500, 500, 0, ['--voucher='.$voucher->code]);

        $this->assertContains('linked', $outcomes, 'one side must get its link');
        $this->assertSame(1, collect($outcomes)->filter(fn ($o) => $o === 'linked')->count(), 'never both');
        $this->assertSame(0, $voucher->fresh()->remaining);
        $this->assertSame(1, VoucherRedemption::query()->count());
        $this->assertEveryLinkedOrderIsReserved();
    }

    public function test_two_member_checkouts_never_both_get_a_link_on_one_quota(): void
    {
        ['game' => $game, 'package' => $package] = $this->catalog();
        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'buyer@example.com',
            'membership_plan_id' => MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail()->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 1500, // one member-priced order (1040) fits, two don't
            'expires_at' => now()->addDays(20),
        ]);

        $outcomes = $this->race($game, $package, 1000, 1200, 20, ['--membership='.$membership->id]);

        $this->assertContains('linked', $outcomes);
        $this->assertSame(1, MembershipQuotaDebit::query()->count(), 'quota debited exactly once');
        $this->assertSame(460, $membership->fresh()->quota_remaining_sen);
        $this->assertEveryLinkedOrderIsReserved();
    }

    /** @return array{game: Game, package: Package} */
    private function catalog(): array
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends-reserve-race', 'is_active' => true]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '500 Diamonds', 'denomination' => 500,
            'cost_price' => 1000, 'standard_selling_price' => 1200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'reserve-race',
        ]);

        return ['game' => $game, 'package' => $package];
    }

    /** @return list<string> */
    private function race(Game $game, Package $package, int $cost, int $selling, int $markup, array $options): array
    {
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
            'QUEUE_CONNECTION' => 'database',
        ];

        $files = [tempnam(sys_get_temp_dir(), 'reserve_race_a_'), tempnam(sys_get_temp_dir(), 'reserve_race_b_')];
        $processes = [];
        foreach ($files as $i => $file) {
            $processes[] = Process::path(base_path())->env($env)->start([
                PHP_BINARY, 'artisan', 'app:checkout-test-initiate',
                (string) $this->primaryAffiliate()->id, (string) $game->id, (string) $package->id,
                (string) $cost, (string) $selling, (string) $markup, "idem-reserve-race-{$i}", $file,
                ...$options,
            ]);
        }

        foreach ($processes as $process) {
            $process->wait();
        }

        $outcomes = [];
        foreach ($files as $file) {
            $outcome = (string) file_get_contents($file);
            unlink($file);
            $this->assertStringNotContainsString('error:', $outcome, "A process errored: {$outcome}");
            $outcomes[] = str_starts_with($outcome, 'checkout_closed') ? 'checkout_closed' : $outcome;
        }

        return $outcomes;
    }

    private function assertEveryLinkedOrderIsReserved(): void
    {
        $linked = Order::query()->where('payment_status', 'pending')->whereNotNull('payment_ref')->get();

        foreach ($linked as $order) {
            if ($order->voucher_id !== null) {
                $this->assertTrue(VoucherRedemption::query()->where('order_id', $order->id)->exists(), "{$order->order_number} has a link but no voucher reservation");
            }
            if ($order->membership_id !== null) {
                $this->assertTrue(MembershipQuotaDebit::query()->where('order_id', $order->id)->exists(), "{$order->order_number} has a link but no quota debit");
            }
        }

        $failedIds = Order::query()->where('payment_status', 'failed')->pluck('id');
        $this->assertSame(0, VoucherRedemption::query()->whereIn('order_id', $failedIds)->where('status', 'reserved')->count(), 'a failed-closed order must hold no voucher reservation');
        $this->assertSame(0, MembershipQuotaDebit::query()->whereIn('order_id', $failedIds)->whereNull('restored_at')->count(), 'a failed-closed order must hold no quota debit');
    }
}
