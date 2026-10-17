<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Jobs\FulfillOrderJob;
use App\Jobs\ResendOrderDeliveryJob;
use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\TestCase;

/** This file: retry delivery and resend (package swap), and their guards. */
class OrderControllerRetryResendTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use RefreshDatabase;

    /**
     * ORD-7 / ADR-014: the manual retry action queues FulfillOrderJob
     * rather than running it inline, same as the webhook path.
     */
    public function test_retry_delivery_queues_a_fulfillment_job_for_a_failed_order(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertOk();
        Queue::assertPushed(FulfillOrderJob::class, fn (FulfillOrderJob $job) => $job->order->id === $order->id);
    }

    /**
     * ADR-106 addendum (2026-09-21) — retryDelivery() now threads the
     * admin's identity through to FulfillOrderJob, closing the gap
     * where a combo (or plain) retry left zero durable trace of WHO
     * triggered it. `override_reason` doubles as this action's only
     * free-text field (no separate `note` input exists for retry),
     * threaded through as this job's `note`.
     */
    public function test_retry_delivery_threads_the_admins_name_and_override_reason_into_the_job(): void
    {
        Queue::fake();
        $admin = AdminUser::factory()->create(['role' => 'admin', 'name' => 'Farres Yahaya']);
        Sanctum::actingAs($admin);
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery", [
            'override_reason' => 'Confirmed cleared with Gamevion support',
        ]);

        $response->assertOk();
        Queue::assertPushed(FulfillOrderJob::class, function (FulfillOrderJob $job) use ($order) {
            return $job->order->id === $order->id
                && $job->triggeredBy === 'Farres Yahaya'
                && $job->note === 'Confirmed cleared with Gamevion support';
        });
    }

    /** A plain retry with no override_reason still queues, with a null note (not an empty string). */
    public function test_retry_delivery_queues_with_a_null_note_when_no_override_reason_given(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertOk();
        Queue::assertPushed(FulfillOrderJob::class, fn (FulfillOrderJob $job) => $job->note === null && $job->triggeredBy !== null);
    }

    public function test_retry_delivery_rejects_an_order_that_is_not_failed(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    /**
     * ADR-024 decision #8 — once a voucher has been issued for this
     * order (the admin's own "give up" decision), a later successful
     * resend must never be possible, or the customer would be
     * double-compensated (goods delivered and a voucher already held).
     */
    public function test_retry_delivery_rejects_an_order_with_an_already_issued_voucher(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        Voucher::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $order->affiliate_id,
            'code' => 'KRS-GUARD-TEST',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    /**
     * ADR-102 decision 1/2 — a real prod gap this closes: the old
     * guard only ever checked `Voucher::exists()`, never a
     * reseller-wallet refund already paid out (order `PG-CGDZOLEHAIR8`
     * was refunded to wallet, `failed`, and nothing stopped a further
     * resend from also delivering the goods). No override exists for
     * this one, unlike the RC-futile case — it's a hard block.
     */
    public function test_retry_delivery_rejects_an_order_that_has_been_refunded_to_wallet(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $reseller = $this->walletReseller();
        $order = $this->order([
            'wallet_reseller_id' => $reseller->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
            'final_amount' => 945,
        ]);
        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertOk();

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    /**
     * ADR-026 decision 4b — the same retry mechanism resolves a
     * needs_review order, not just a plain Failed one.
     */
    public function test_retry_delivery_queues_a_fulfillment_job_for_a_needs_review_order(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertOk();
        Queue::assertPushed(FulfillOrderJob::class, fn (FulfillOrderJob $job) => $job->order->id === $order->id);
    }

    /**
     * ADR-102 decision 3 — a scoped-unsafe order (non-combo, NeedsReview,
     * raw signal true) requires a logged override_reason; omitting it
     * is a 422, never a silent no-op.
     */
    public function test_retry_delivery_requires_an_override_reason_for_a_scoped_unsafe_needs_review_order(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $order = $this->order([
            'supplier_id' => $supplier->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
            'supplier_response' => ['error_code' => '02', 'error_message' => 'Transaksi Gagal'],
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    /** ADR-102 decision 3 — the override is real, not just gatekept: a reason lets it through and queues the job. */
    public function test_retry_delivery_proceeds_with_an_override_reason_for_a_scoped_unsafe_needs_review_order(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $order = $this->order([
            'supplier_id' => $supplier->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
            'supplier_response' => ['error_code' => '02', 'error_message' => 'Transaksi Gagal'],
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery", [
            'override_reason' => 'Founder confirmed via Digiflazz dashboard this is worth retrying.',
        ]);

        $response->assertOk();
        Queue::assertPushed(FulfillOrderJob::class, fn (FulfillOrderJob $job) => $job->order->id === $order->id);
    }

    /**
     * ADR-102 decision 3 — the scoped rule's whole point: a Failed
     * non-combo order is NEVER scoped-unsafe (decision 9 always
     * regenerates its reference), so no override is required even with
     * the raw signal true.
     */
    public function test_retry_delivery_does_not_require_an_override_reason_for_a_failed_non_combo_order(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $order = $this->order([
            'supplier_id' => $supplier->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
            'supplier_response' => ['error_code' => '02', 'error_message' => 'Transaksi Gagal'],
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertOk();
        Queue::assertPushed(FulfillOrderJob::class, fn (FulfillOrderJob $job) => $job->order->id === $order->id);
    }

    /**
     * ADR-094 decision 10 (2026-09-15 admin-UI fix): retry-delivery is
     * the ONLY resend/retry path that actually works for a combo order
     * — resend() always 422s one (assertSameGamePackage()'s is_combo
     * guard fires unconditionally, even for a same-package "swap"),
     * found live via a real local smoke test. The admin panel now
     * routes combo orders here instead of opening the package-swap
     * modal at all.
     */
    public function test_retry_delivery_queues_a_fulfillment_job_for_a_combo_order(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $combo = Package::query()->create([
            'game_id' => Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia-'.uniqid()])->id,
            'name' => '10478 Diamonds (Combo)', 'is_combo' => true,
            'denomination' => 10478, 'cost_price' => 85000, 'standard_selling_price' => 93500,
        ]);
        $order = $this->order([
            'package_id' => $combo->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertOk();
        Queue::assertPushed(FulfillOrderJob::class, fn (FulfillOrderJob $job) => $job->order->id === $order->id);
    }

    /**
     * ADR-017: same async-dispatch discipline as retryDelivery — the
     * admin request never blocks on a live Gamevion call.
     */
    public function test_resend_queues_a_resend_job_for_a_failed_order_with_a_same_game_package(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        // ADR-105: kept under the order's own standard_selling_price
        // (900, the order() default) so this resend doesn't trip
        // decision 4's below-cost guard — this test is about job
        // dispatch, not that guard.
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 850, 'standard_selling_price' => 850,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id, 'note' => 'Bigger pack']);

        $response->assertOk();
        Queue::assertPushed(ResendOrderDeliveryJob::class, fn (ResendOrderDeliveryJob $job) => $job->order->id === $order->id
            && $job->packageId === $package->id
            && $job->note === 'Bigger pack');
    }

    /** ADR-102 decision 10 — the optional Player ID/Server ID correction is carried through the queued job. */
    public function test_resend_carries_a_player_id_and_server_id_correction_through_to_the_job(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        // ADR-105: kept under the order's own standard_selling_price
        // (900, the order() default) so this resend doesn't trip
        // decision 4's below-cost guard — this test is about the
        // player-ID correction, not that guard.
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 850, 'standard_selling_price' => 850,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        $order = $this->order([
            'game_id' => $game->id,
            'player_id' => 'typo-id',
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", [
            'package_id' => $package->id,
            'player_id' => 'corrected-id',
            'server_id' => 'srv-9',
        ]);

        $response->assertOk();
        Queue::assertPushed(ResendOrderDeliveryJob::class, fn (ResendOrderDeliveryJob $job) => $job->order->id === $order->id
            && $job->playerId === 'corrected-id'
            && $job->serverId === 'srv-9');
    }

    /**
     * ADR-105 decision 4 — fast, same-request echo of the guard
     * `OrderResendService::resend()` enforces for real: a package whose
     * live cost now exceeds what the customer already paid is a 422
     * without an override reason, never a silently-queued job that
     * fails later.
     */
    public function test_resend_requires_an_override_reason_when_the_swap_packages_cost_exceeds_what_the_customer_paid(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 1900, 'standard_selling_price' => 1900,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id]);

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    /** ADR-105 decision 4 — the override is real, not just gatekept: a reason lets it through and queues the job, carried through to the service. */
    public function test_resend_proceeds_with_an_override_reason_when_the_swap_packages_cost_exceeds_what_the_customer_paid(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 1900, 'standard_selling_price' => 1900,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", [
            'package_id' => $package->id,
            'override_reason' => 'Customer already paid, deliver anyway per founder instruction.',
        ]);

        $response->assertOk();
        Queue::assertPushed(ResendOrderDeliveryJob::class, fn (ResendOrderDeliveryJob $job) => $job->order->id === $order->id
            && $job->overrideReason === 'Customer already paid, deliver anyway per founder instruction.');
    }

    /** @param array<string, mixed> $orderOverrides */
    #[DataProvider('lossOnEveryBasis')]
    public function test_resend_requires_an_override_reason_for_a_loss_on_every_basis(array $orderOverrides): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 1200, 'standard_selling_price' => 1500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        $order = $this->order(array_merge([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ], $orderOverrides));

        $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('override_reason');
        Queue::assertNothingPushed();
    }

    /** ADR-105 2026-10-06 decision 15 — the modal's preview comes from the backend, per package. */
    public function test_resend_options_lists_the_games_active_packages_with_each_ones_impact(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $otherGame = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $cheaper = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 850, 'standard_selling_price' => 950,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        $dearer = Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 1100, 'standard_selling_price' => 1200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Inactive', 'cost_price' => 500, 'standard_selling_price' => 600,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'C', 'is_active' => false,
        ]);
        Package::query()->create([
            'game_id' => $otherGame->id, 'name' => 'Other game', 'cost_price' => 500, 'standard_selling_price' => 600,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'D', 'is_active' => true,
        ]);
        $order = $this->order([
            'game_id' => $game->id,
            'package_id' => $cheaper->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->getJson("/api/orders/{$order->id}/resend-options")->assertOk();

        $this->assertSame([$cheaper->id, $dearer->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.supplier_package_ref', 'A');
        $response->assertJsonPath('data.0.impact.platform_profit', 150); // 1000 − 850 − 0
        $response->assertJsonPath('data.0.impact.override_required', false);
        $response->assertJsonPath('data.1.impact.platform_profit', -100); // 1000 − 1100 − 0
        $response->assertJsonPath('data.1.impact.override_required', true);
        $response->assertJsonPath('data.1.impact.cost_diff', 200);
        $response->assertJsonPath('data.1.impact.blocked_reason', null);
    }

    public function test_resend_options_404s_for_a_sandbox_order(): void
    {
        $this->actingAsAdmin();
        $order = $this->order(['is_test' => true, 'delivery_status' => DeliveryStatus::Failed->value, 'payment_status' => PaymentStatus::Paid->value]);

        $this->getJson("/api/orders/{$order->id}/resend-options")->assertNotFound();
    }

    /**
     * ADR-024 decision #8 — see the identical retryDelivery() guard
     * test above for the full reasoning.
     */
    public function test_resend_rejects_an_order_with_an_already_issued_voucher(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 1900, 'standard_selling_price' => 1900,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        Voucher::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $order->affiliate_id,
            'code' => 'KRS-GUARD-TEST-2',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id]);

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    /**
     * ADR-102 decision 1/2 — see the identical retry-delivery test
     * above for the full reasoning; the resend (package-swap) entry
     * point needs the same hard block.
     */
    public function test_resend_rejects_an_order_that_has_been_refunded_to_wallet(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 1900, 'standard_selling_price' => 1900,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        $reseller = $this->walletReseller();
        $order = $this->order([
            'game_id' => $game->id,
            'wallet_reseller_id' => $reseller->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
            'final_amount' => 945,
        ]);
        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertOk();

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id]);

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_resend_rejects_an_order_that_is_not_failed(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create(['game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 900, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id]);

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    /**
     * Decision #1: same-game swap only — cross-game rejected before
     * ever reaching the queue.
     */
    public function test_resend_rejects_a_package_from_a_different_game(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $otherGame = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $otherGamePackage = Package::query()->create(['game_id' => $otherGame->id, 'name' => '5 Diamonds', 'cost_price' => 500, 'standard_selling_price' => 500, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B']);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $otherGamePackage->id]);

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_retry_delivery_404s_for_a_sandbox_order(): void
    {
        $order = $this->order(['is_test' => true, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $this->postJson("/api/orders/{$order->id}/retry-delivery")->assertNotFound();
    }

    public function test_resend_404s_for_a_sandbox_order(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create(['game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 900, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $order = $this->order([
            'is_test' => true,
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id])->assertNotFound();
    }

    /**
     * ADR-024 addendum — the guard gap this closes: a full-cover order
     * that already had its voucher restored must be just as untouchable
     * as one with a Voucher row or wallet refund, or a further resend
     * could deliver goods on top of a refund already given back.
     */
    public function test_resend_rejects_an_order_whose_voucher_has_already_been_restored(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 1900, 'standard_selling_price' => 1900,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        $originalVoucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-RESTORED-2',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);
        $order = $this->order([
            'game_id' => $game->id,
            'voucher_id' => $originalVoucher->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        VoucherRedemption::query()->create([
            'voucher_id' => $originalVoucher->id,
            'order_id' => $order->id,
            'amount' => 500,
            'status' => 'restored',
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id]);

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }
}
