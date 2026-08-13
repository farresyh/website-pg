<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-024 decision #1's "Apply" button — read-only, never locks or
 * mutates a voucher's remaining balance (see VoucherServiceTest for
 * the locked redeem()/commit()/restore() behavior).
 */
class VoucherPreviewControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{game: Game, package: Package} */
    private function gameAndPackage(): array
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'reseller_cost_price' => 500,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);

        return ['game' => $game, 'package' => $package];
    }

    public function test_returns_discount_capped_at_selling_price(): void
    {
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500
        $voucher = Voucher::query()->create([
            'code' => 'KRS-PREVIEW-CTRL',
            'customer_email' => 'buyer@example.com',
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $response = $this->postJson('/api/vouchers/preview', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'voucher_code' => $voucher->code,
            'customer_email' => 'buyer@example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('selling_price', 500);
        $response->assertJsonPath('discount', 500);
        $response->assertJsonPath('remaining_after', 500);

        // Never locks — remaining is untouched by a preview call.
        $this->assertSame(1000, $voucher->fresh()->remaining);
    }

    public function test_rejects_a_voucher_that_does_not_belong_to_this_customer(): void
    {
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $voucher = Voucher::query()->create([
            'code' => 'KRS-PREVIEW-CTRL-2',
            'customer_email' => 'owner@example.com',
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $response = $this->postJson('/api/vouchers/preview', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'voucher_code' => $voucher->code,
            'customer_email' => 'stranger@example.com',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('voucher_code');
    }
}
