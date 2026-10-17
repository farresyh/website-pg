<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\BlacklistEntry;
use App\Models\Order;
use App\Services\Fraud\BlacklistEntryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\CheckoutControllerFixtures;
use Tests\TestCase;

/** This file: blacklist, velocity guard and rate limits. */
class CheckoutControllerFraudGuardTest extends TestCase
{
    use CheckoutControllerFixtures;
    use RefreshDatabase;

    /**
     * ADR-014: throttle:10,1 — the 11th request from the same IP
     * within a minute must be rejected before it ever reaches
     * CheckoutController, flood-protection independent of validation.
     */
    public function test_rate_limits_repeated_requests_from_the_same_ip(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/checkout', $this->payload($game, $package))->assertCreated();
        }

        $this->postJson('/api/checkout', $this->payload($game, $package))
            ->assertStatus(429);
    }

    /**
     * ADR-014 addendum (2026-09-29, PR-D): mobile carriers put many
     * customers behind one CGNAT address — during a promo, different
     * buyers sharing an IP must not throttle each other. The per-customer
     * 10/min bucket stays; the shared IP gets a 60/min ceiling.
     */
    public function test_different_customers_behind_one_ip_do_not_throttle_each_other(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        for ($i = 0; $i < 15; $i++) {
            $this->postJson('/api/checkout', $this->payload($game, $package, ['customer_email' => "buyer{$i}@example.com"]))
                ->assertCreated();
        }
    }

    public function test_rejects_checkout_for_a_blacklisted_player_id(): void
    {
        $this->bindGateway();
        BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value,
            'value' => '123456789',
            'reason' => 'Prior chargeback',
            'is_active' => true,
        ]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('player_id');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_rejects_checkout_for_a_blacklisted_email(): void
    {
        $this->bindGateway();
        BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::Email->value,
            'value' => 'buyer@example.com',
            'reason' => 'Known fraud contact',
            'is_active' => true,
        ]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertUnprocessable();
        $this->assertSame(0, Order::query()->count());
    }

    public function test_does_not_block_checkout_for_an_inactive_blacklist_entry(): void
    {
        $this->bindGateway();
        BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value,
            'value' => '123456789',
            'reason' => 'previously blocked, now cleared',
            'is_active' => false,
        ]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertCreated();
    }

    public function test_a_blocked_attempt_is_recorded_as_a_hit(): void
    {
        $this->bindGateway();
        $entry = BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value,
            'value' => '123456789',
            'reason' => 'Prior chargeback',
            'is_active' => true,
        ]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package));

        $this->assertDatabaseHas('blacklist_hits', [
            'blacklist_entry_id' => $entry->id,
            'player_id' => '123456789',
        ]);
    }

    /**
     * FRAUD-4: distinct from the general throttle:10,1 - this trips
     * on repeated blacklist-triggered rejections specifically, well
     * before the general throttle's own 10-request ceiling.
     */
    public function test_velocity_guard_blocks_further_attempts_after_repeated_blacklist_hits(): void
    {
        $this->bindGateway();
        BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value,
            'value' => '123456789',
            'reason' => 'Prior chargeback',
            'is_active' => true,
        ]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        // Default test threshold (config/fraud.php) is 3.
        $this->postJson('/api/checkout', $this->payload($game, $package))->assertUnprocessable();
        $this->postJson('/api/checkout', $this->payload($game, $package))->assertUnprocessable();
        $this->postJson('/api/checkout', $this->payload($game, $package))->assertUnprocessable();

        // A fresh, otherwise-valid attempt from the same IP is still
        // blocked - the velocity trip applies regardless of whether
        // *this* attempt matches the blacklist.
        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['player_id' => '999999999']));

        $response->assertUnprocessable();
        $this->assertSame(0, Order::query()->count());
    }
}
