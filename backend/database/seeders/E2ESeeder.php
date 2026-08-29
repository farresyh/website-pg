<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * ADR-023 decision #7: the thin, explicitly-owned fixture layer for
 * the 3 Playwright golden-path tests. Reuses DatabaseSeeder as-is
 * (admin user `test@example.com`/`password`, the platform-owner
 * Reseller, the real PaymentMethodSeeder catalog) rather than
 * duplicating it, then adds exactly the rows the 3 specs assert
 * against: one Game+Package with no player-ID validation (keeps the
 * storefront wizard's Step 1 to a plain "Continue" gate — validator
 * chains are a separate, unfaked third-party mechanism this ADR never
 * scoped to touch), one active Xendit channel (checkout requires at
 * least one), and pre-existing orders for each admin golden path —
 * one failed order for the Issue Voucher test, a separate failed order
 * for the Resend Delivery test, and a needs_review order for the Mark
 * as Delivered test (ADR-026/ADR-023 Trigger A) — so neither test's own
 * state change (issuing a voucher; resending to "delivered"; marking
 * delivered) can affect the others regardless of run order. Whoever's
 * PR changes Game/Package/Order/PaymentMethod schema owns updating
 * this file in the same PR — see ADR-023 decision #7.
 */
class E2ESeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        // AMBANK_FPX — confirmed live-tested successfully against the
        // real Xendit sandbox (ADR-001's 2026-07-25 addendum). Every
        // other seeded row stays is_active=false, matching production
        // seeding convention (PaymentMethodSeeder's own doc comment).
        PaymentMethod::query()->where('channel_code', 'AMBANK_FPX')->update(['is_active' => true]);

        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'e2e-fake-supplier'],
            ['name' => 'E2E Fake Supplier', 'api_config' => [], 'currency' => 'MYR'],
        );

        $game = Game::query()->firstOrCreate(
            ['slug' => 'e2e-test-game'],
            [
                'name' => 'E2E Test Game',
                'category' => 'Mobile Game',
                'is_active' => true,
                // No validator profile — Step 1 of the storefront
                // wizard renders a plain "Continue" gate, no
                // third-party validator call to fake or await.
                'player_validator_enabled' => false,
                'validation_rules' => null,
            ],
        );

        $package = Package::query()->firstOrCreate(
            ['game_id' => $game->id, 'name' => 'E2E Test Package'],
            [
                'cost_price' => 400,
                'standard_selling_price' => 500,
                'markup_percent' => 20,
                'is_active' => true,
                'supplier_id' => $supplier->id,
                // Ignored by FakeSupplierAdapter (ADR-023 decision #6)
                // — never reaches a real Gamevion catalog lookup.
                'supplier_package_ref' => 'E2E-TEST-REF',
            ],
        );

        // Fixture for the "admin → failed order → Issue Voucher"
        // golden path — a pre-existing failed delivery so that test
        // doesn't also have to drive a checkout to failure first.
        Order::query()->firstOrCreate(
            ['order_number' => 'KRS-E2E-VOUCHER-FIXTURE'],
            [
                'reference_number' => 'REF-E2E-VOUCHER-FIXTURE',
                'is_test' => false,
                'customer_email' => 'e2e-voucher-fixture@example.com',
                'customer_name' => 'E2E Voucher Fixture',
                'customer_phone' => '0100000000',
                'player_id' => '000000',
                'game_id' => $game->id,
                'package_id' => $package->id,
                'supplier_id' => $supplier->id,
                'cost_price' => $package->cost_price,
                'standard_selling_price' => $package->standard_selling_price,
                'selling_price' => 600,
                'transaction_fee' => 21,
                'final_amount' => 621,
                'platform_profit' => 100,
                'reseller_profit' => 0,
                'payment_status' => PaymentStatus::Paid->value,
                'delivery_status' => DeliveryStatus::Failed->value,
                'payment_gateway' => 'xendit',
            ],
        );

        // Fixture for the "admin → failed order → Resend Delivery"
        // golden path — kept fully separate from the voucher fixture
        // above so the two tests never contend over the same row.
        Order::query()->firstOrCreate(
            ['order_number' => 'KRS-E2E-RESEND-FIXTURE'],
            [
                'reference_number' => 'REF-E2E-RESEND-FIXTURE',
                'is_test' => false,
                'customer_email' => 'e2e-resend-fixture@example.com',
                'customer_name' => 'E2E Resend Fixture',
                'customer_phone' => '0100000001',
                'player_id' => '000001',
                'game_id' => $game->id,
                'package_id' => $package->id,
                'supplier_id' => $supplier->id,
                'cost_price' => $package->cost_price,
                'standard_selling_price' => $package->standard_selling_price,
                'selling_price' => 600,
                'transaction_fee' => 21,
                'final_amount' => 621,
                'platform_profit' => 100,
                'reseller_profit' => 0,
                'payment_status' => PaymentStatus::Paid->value,
                'delivery_status' => DeliveryStatus::Failed->value,
                'payment_gateway' => 'xendit',
            ],
        );

        // Fixture for the "admin -> needs_review order -> Mark as
        // Delivered" golden path (ADR-026/ADR-023 Trigger A — a new
        // order-resolution mechanism beyond retry-delivery/voucher).
        // Kept fully separate from the two fixtures above.
        Order::query()->firstOrCreate(
            ['order_number' => 'KRS-E2E-NEEDSREVIEW-FIXTURE'],
            [
                'reference_number' => 'REF-E2E-NEEDSREVIEW-FIXTURE',
                'is_test' => false,
                'customer_email' => 'e2e-needsreview-fixture@example.com',
                'customer_name' => 'E2E NeedsReview Fixture',
                'customer_phone' => '0100000002',
                'player_id' => '000002',
                'game_id' => $game->id,
                'package_id' => $package->id,
                'supplier_id' => $supplier->id,
                'cost_price' => $package->cost_price,
                'standard_selling_price' => $package->standard_selling_price,
                'selling_price' => 600,
                'transaction_fee' => 21,
                'final_amount' => 621,
                'platform_profit' => 100,
                'reseller_profit' => 0,
                'payment_status' => PaymentStatus::Paid->value,
                'delivery_status' => DeliveryStatus::NeedsReview->value,
                'payment_gateway' => 'xendit',
            ],
        );
    }
}
