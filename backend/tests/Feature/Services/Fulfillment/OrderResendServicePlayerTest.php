<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PlayerValidation;
use App\Models\PlayerValidatorProfile;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\OrderResendFixtures;
use Tests\TestCase;

/**
 * ADR-017: same-game package swap + live price reconciliation on top
 * of OrderFulfillmentService's own untouched fulfill() contract.
 *
 * This file: player validation and player ID / server corrections on resend.
 */
class OrderResendServicePlayerTest extends TestCase
{
    use OrderResendFixtures;
    use RefreshDatabase;

    /**
     * Decision #6: reuses the same player_validations gate
     * CheckoutController enforces — a game with validation enabled but
     * no recent valid row blocks the resend entirely.
     */
    public function test_blocks_resend_when_game_requires_player_validation_and_none_is_on_file(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $game->update([
            'player_validator_enabled' => true,
            'player_validator_profile_id' => PlayerValidatorProfile::query()->create([
                'name' => 'MLBB Validator', 'key' => 'mlbb',
            ])->id,
        ]);
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $package, null, 'Admin');
    }

    public function test_allows_resend_when_player_validation_is_on_file_and_recent(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $game->update([
            'player_validator_enabled' => true,
            'player_validator_profile_id' => PlayerValidatorProfile::query()->create([
                'name' => 'MLBB Validator', 'key' => 'mlbb',
            ])->id,
        ]);
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);
        PlayerValidation::query()->create([
            'game_id' => $game->id,
            'player_id' => $order->player_id,
            'server_id' => $order->server_id,
            'status' => 'valid',
            'validated_at' => now(),
        ]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $package, null, 'Admin');

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
    }

    /**
     * ADR-102 decision 10 — the optional Player ID/Server ID correction
     * closes Context point 7: today, a wrong-ID failure can only be
     * resolved via Issue Voucher + a brand new customer-placed order.
     * The corrected value is what actually gets persisted and sent to
     * the supplier.
     */
    public function test_applies_a_player_id_correction_before_resending(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $game->update(['validation_rules' => ['extra_field' => 'server_id']]);
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['player_id' => '11111']);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $package, null, 'Admin', playerId: '22222', serverId: '2002');

        $this->assertSame('22222', $result->player_id);
        $this->assertSame('2002', $result->server_id);
        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
    }

    /**
     * The player-ID validation gate (decision #6, unchanged) must
     * re-check against the CORRECTED id, not the order's original one
     * — a validation row that only covers the typo'd original id must
     * not let a resend through for a still-unvalidated corrected id.
     */
    public function test_blocks_resend_when_validation_is_only_on_file_for_the_original_not_the_corrected_player_id(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $game->update([
            'player_validator_enabled' => true,
            'player_validator_profile_id' => PlayerValidatorProfile::query()->create([
                'name' => 'MLBB Validator', 'key' => 'mlbb',
            ])->id,
        ]);
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['player_id' => 'typo-id']);
        PlayerValidation::query()->create([
            'game_id' => $game->id,
            'player_id' => 'typo-id',
            'server_id' => $order->server_id,
            'status' => 'valid',
            'validated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))
            ->resend($order, $package, null, 'Admin', playerId: 'corrected-id');
    }

    /** An empty-string player_id is "no correction given", not a literal blank value to submit — the original is kept. */
    public function test_an_empty_player_id_correction_is_ignored(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['player_id' => 'original-id']);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $package, null, 'Admin', playerId: '');

        $this->assertSame('original-id', $result->player_id);
    }

    /** An empty-string server_id, unlike player_id, IS a real correction — clears it (some games have none). */
    public function test_an_empty_server_id_correction_clears_it(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['server_id' => 'wrong-server']);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $package, null, 'Admin', serverId: '');

        $this->assertNull($result->server_id);
    }

    /** ADR-097 2026-10-05 addendum, decision 31 — a corrected ID meets the same per-game contract. */
    public function test_rejects_a_malformed_player_id_correction(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['player_id' => '11111']);

        try {
            $this->service($this->fakeSupplierAdapter(true))->resend($order, $package, null, 'Admin', playerId: '22222 (2001)');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('player_id', $e->errors());
        }

        $this->assertSame('11111', $order->fresh()->player_id);
    }
}
