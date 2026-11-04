<?php

namespace Tests\Concurrency;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-118 V5 + V8: one customer races two KOLs' codes (and, separately,
 * one code) in two genuinely separate processes. The money invariant: one
 * KOL discount per customer per brand — never two reservations, and no
 * order holding a link without its reservation.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class PromoCodeReserveConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_one_customer_racing_two_kols_codes_gets_one_discount(): void
    {
        ['game' => $game, 'package' => $package] = $this->catalog();
        $this->code('KOLA');
        $this->code('KOLB');

        $outcomes = $this->race($game, $package, ['--promo=KOLA'], ['--promo=KOLB']);

        $this->assertContains('linked', $outcomes, 'one side must get its link');
        $this->assertSame(1, collect($outcomes)->filter(fn ($o) => $o === 'linked')->count(), 'never both');
        $this->assertSame(1, PromoCodeRedemption::query()->count());
        $this->assertEveryLinkedOrderIsReserved();
    }

    public function test_one_customer_racing_one_code_twice_gets_one_discount(): void
    {
        ['game' => $game, 'package' => $package] = $this->catalog();
        $this->code('KOLA');

        $outcomes = $this->race($game, $package, ['--promo=KOLA'], ['--promo=KOLA']);

        $this->assertSame(1, collect($outcomes)->filter(fn ($o) => $o === 'linked')->count());
        $this->assertSame(1, PromoCodeRedemption::query()->count());
        $this->assertEveryLinkedOrderIsReserved();
    }

    /** @return array{game: Game, package: Package} */
    private function catalog(): array
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends-promo-race', 'is_active' => true]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '500 Diamonds', 'denomination' => 500,
            'cost_price' => 1000, 'standard_selling_price' => 1200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'promo-race',
        ]);

        return ['game' => $game, 'package' => $package];
    }

    private function code(string $code): void
    {
        $campaign = Campaign::query()->create(['affiliate_id' => $this->primaryAffiliate()->id, 'name' => $code]);
        PromoCode::query()->create(['campaign_id' => $campaign->id, 'code' => $code, 'discount_percent' => 5, 'budget_sen' => 100_000, 'is_active' => true]);
    }

    /** @return list<string> */
    private function race(Game $game, Package $package, array $optionsA, array $optionsB): array
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

        $files = [tempnam(sys_get_temp_dir(), 'promo_race_a_'), tempnam(sys_get_temp_dir(), 'promo_race_b_')];
        $processes = [];
        foreach ([$optionsA, $optionsB] as $i => $options) {
            $processes[] = Process::path(base_path())->env($env)->start([
                PHP_BINARY, 'artisan', 'app:checkout-test-initiate',
                (string) $this->primaryAffiliate()->id, (string) $game->id, (string) $package->id,
                '1000', '1200', '20', "idem-promo-race-{$i}", $files[$i],
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
        foreach (Order::query()->where('payment_status', 'pending')->whereNotNull('payment_ref')->get() as $order) {
            $this->assertTrue(PromoCodeRedemption::query()->where('order_id', $order->id)->exists(), "{$order->order_number} has a link but no promo reservation");
        }

        $failedIds = Order::query()->where('payment_status', 'failed')->pluck('id');
        $this->assertSame(0, PromoCodeRedemption::query()->whereIn('order_id', $failedIds)->count(), 'a failed-closed order must hold no promo reservation');
    }
}
