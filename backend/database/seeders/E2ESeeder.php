<?php

namespace Database\Seeders;

use App\Models\Affiliate;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\MembershipOtpCode;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PlatformSettings;
use App\Models\PromoCode;
use App\Models\Redirect;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * ADR-023 decision #7: the thin, explicitly-owned fixture layer for
 * the 3 Playwright golden-path tests. Reuses DatabaseSeeder as-is
 * (admin user `test@example.com`/`password`, the platform-owner
 * Affiliate, the real PaymentMethodSeeder catalog) rather than
 * duplicating it, then adds exactly the rows the 3 specs assert
 * against: one Game+Package with no player-ID validation (keeps the
 * storefront wizard's Step 1 to a plain "Continue" gate — validator
 * chains are a separate, unfaked third-party mechanism this ADR never
 * scoped to touch), one active CHIP channel (checkout requires at
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

        // ADR-061 PR-B: `orders.affiliate_id` is NOT NULL — every fixture
        // order below is the primary brand's (the one `DatabaseSeeder`
        // just seeded).
        $affiliate = Affiliate::primary();

        // ADR-078 2026-11-08 addendum: storefront-proxy.spec's saved redirect.
        Redirect::create([
            'affiliate_id' => $affiliate->id,
            'from_path' => '/e2e-old-path',
            'to_path' => '/track-order',
            'status_code' => 301,
        ]);

        // CHIP FPX (`fpx`) — the one channel the golden-path checkout
        // spec drives through. Every other seeded row stays
        // is_active=false, matching production seeding convention
        // (PaymentMethodSeeder's own doc comment). The checkout spec's
        // real payment leg needs a CHIP test-mode key (CHIP_SECRET_KEY /
        // CHIP_BRAND_ID) exported before `npm test` — see e2e/AGENTS.md.
        PaymentMethod::query()->where('channel_code', 'fpx')->update(['is_active' => true]);

        // ADR-068 — the self-serve membership-subscribe golden path.
        // Membership on for the primary brand (the effective gate needs
        // both this and the affiliate's own toggle, which primaryAffiliate
        // seeds true), plus a pre-verifiable OTP fixture so the spec can
        // obtain a real 30-day session token without an email round-trip
        // (MAIL_MAILER=log in e2e). The seeded code hash matches
        // E2E_MEMBER_OTP in e2e/tests/constants.ts.
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $affiliate->update(['membership_enabled' => true]);

        MembershipOtpCode::query()->firstOrCreate(
            ['affiliate_id' => $affiliate->id, 'email' => 'e2e-member@example.com', 'consumed_at' => null],
            ['code_hash' => Hash::make('123456'), 'expires_at' => now()->addYear()],
        );

        // The landing spec signs in through the real page with its own code,
        // so it never spends the one the subscribe spec uses.
        MembershipOtpCode::query()->firstOrCreate(
            ['affiliate_id' => $affiliate->id, 'email' => 'e2e-landing@example.com', 'consumed_at' => null],
            ['code_hash' => Hash::make('123456'), 'expires_at' => now()->addYear()],
        );

        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'e2e-fake-supplier'],
            ['name' => 'E2E Fake Supplier', 'api_config' => [], 'currency' => 'MYR'],
        );

        // ADR-125: a manual supplier for the login top-up golden path
        // (e2e/tests/admin-login-topup.spec.ts). No fake needed: the real
        // ManualSupplierAdapter makes no network call, it only answers
        // Pending so the order lands in the Manual Queue.
        Supplier::query()->firstOrCreate(
            ['slug' => 'e2e-manual-supplier'],
            ['name' => 'E2E Manual Supplier', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR', 'is_active' => true],
        );

        // ADR-125 PR 2: the supplier WhatsApp bot golden path
        // (e2e/tests/supplier-group-bot.spec.ts). A manual supplier with no
        // group yet (the spec links one in Partners → Suppliers) and its own
        // login game, so it never shares a queue with the spec above.
        $botSupplier = Supplier::query()->firstOrCreate(
            ['slug' => 'e2e-bot-supplier'],
            ['name' => 'E2E Bot Supplier', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR', 'is_active' => true],
        );
        $botGame = Game::query()->firstOrCreate(
            ['slug' => 'e2e-bot-login'],
            [
                'name' => 'E2E Bot Login',
                'category' => 'Mobile Game',
                'is_active' => true,
                'player_validator_enabled' => false,
                'delivery_mode' => 'manual',
                'delivery_subtext' => 'Within 30 minutes',
                'validation_rules' => ['credential_fields' => [
                    ['key' => 'email', 'label' => 'Login email', 'type' => 'email', 'required' => true, 'secret' => false, 'is_identifier' => true],
                    ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'secret' => true, 'is_identifier' => false],
                ]],
            ],
        );
        Package::query()->firstOrCreate(
            ['game_id' => $botGame->id, 'name' => '400 Robux'],
            ['cost_price' => 2000, 'standard_selling_price' => 2000, 'markup_percent' => 15, 'is_active' => true, 'supplier_id' => $botSupplier->id, 'supplier_package_ref' => 'MANUAL-BOT'],
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
                // ADR-120: tokens in a per-game field must render
                // (storefront-seo.spec.ts) — the live bug shipped them raw.
                'seo_title' => '{game_name} Top Up | {store_name}',
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

        // A best seller in the price band the signed-out /membership page
        // compares (ADR-055 2026-11-07 addendum).
        $bestSeller = Package::query()->firstOrCreate(
            ['game_id' => $game->id, 'name' => 'E2E 284 Diamonds'],
            [
                'cost_price' => 1959,
                'standard_selling_price' => 2155,
                'markup_percent' => 10,
                'is_active' => true,
                'supplier_id' => $supplier->id,
                'supplier_package_ref' => 'E2E-BESTSELLER-REF',
            ],
        );
        Order::query()->firstOrCreate(
            ['order_number' => 'PG-E2E-BESTSELLER-FIXTURE'],
            [
                'affiliate_id' => $affiliate->id,
                'reference_number' => 'REF-E2E-BESTSELLER-FIXTURE',
                'is_test' => false,
                'placed_via' => 'storefront',
                'customer_email' => 'e2e-bestseller-fixture@example.com',
                'customer_name' => 'E2E Best Seller Fixture',
                'customer_phone' => '0100000009',
                'player_id' => '000009',
                'game_id' => $game->id,
                'package_id' => $bestSeller->id,
                'supplier_id' => $supplier->id,
                'cost_price' => $bestSeller->cost_price,
                'standard_selling_price' => $bestSeller->standard_selling_price,
                'selling_price' => $bestSeller->standard_selling_price,
                'transaction_fee' => 100,
                'final_amount' => $bestSeller->standard_selling_price + 100,
                'platform_profit' => 196,
                'affiliate_profit' => 0,
                'payment_status' => PaymentStatus::Paid->value,
                'delivery_status' => DeliveryStatus::Delivered->value,
                'payment_gateway' => 'chip',
                'paid_at' => now(),
            ],
        );

        // A KOL code for the promo link spec (ADR-118).
        $campaign = Campaign::query()->firstOrCreate(['affiliate_id' => $affiliate->id, 'name' => 'E2E KOL']);
        PromoCode::query()->firstOrCreate(
            ['code' => 'E2EKOL'],
            ['campaign_id' => $campaign->id, 'discount_percent' => 5, 'budget_sen' => 100000, 'is_active' => true],
        );

        // Fixture for the "admin → failed order → Issue Voucher"
        // golden path — a pre-existing failed delivery so that test
        // doesn't also have to drive a checkout to failure first.
        Order::query()->firstOrCreate(
            ['order_number' => 'PG-E2E-VOUCHER-FIXTURE'],
            [
                'affiliate_id' => $affiliate->id,
                'reference_number' => 'REF-E2E-VOUCHER-FIXTURE',
                'is_test' => false,
                'placed_via' => 'storefront',
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
                'affiliate_profit' => 0,
                'payment_status' => PaymentStatus::Paid->value,
                'delivery_status' => DeliveryStatus::Failed->value,
                'payment_gateway' => 'chip',
            ],
        );

        // Fixture for the "admin → failed order → Resend Delivery"
        // golden path — kept fully separate from the voucher fixture
        // above so the two tests never contend over the same row.
        Order::query()->firstOrCreate(
            ['order_number' => 'PG-E2E-RESEND-FIXTURE'],
            [
                'affiliate_id' => $affiliate->id,
                'reference_number' => 'REF-E2E-RESEND-FIXTURE',
                'is_test' => false,
                'placed_via' => 'storefront',
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
                'affiliate_profit' => 0,
                'payment_status' => PaymentStatus::Paid->value,
                'delivery_status' => DeliveryStatus::Failed->value,
                'payment_gateway' => 'chip',
            ],
        );

        // Fixture for the "admin -> needs_review order -> Mark as
        // Delivered" golden path (ADR-026/ADR-023 Trigger A — a new
        // order-resolution mechanism beyond retry-delivery/voucher).
        // Kept fully separate from the two fixtures above.
        Order::query()->firstOrCreate(
            ['order_number' => 'PG-E2E-NEEDSREVIEW-FIXTURE'],
            [
                'affiliate_id' => $affiliate->id,
                'reference_number' => 'REF-E2E-NEEDSREVIEW-FIXTURE',
                'is_test' => false,
                'placed_via' => 'storefront',
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
                'affiliate_profit' => 0,
                'payment_status' => PaymentStatus::Paid->value,
                'delivery_status' => DeliveryStatus::NeedsReview->value,
                'payment_gateway' => 'chip',
            ],
        );
    }
}
