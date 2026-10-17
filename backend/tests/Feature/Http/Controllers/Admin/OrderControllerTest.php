<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\Affiliate;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\TestCase;

/** This file: the order list, its filters and tabs, the summary and the export. */
class OrderControllerTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use RefreshDatabase;

    public function test_index_lists_orders_with_game_and_package_names(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'standard_selling_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        $this->order(['game_id' => $game->id, 'package_id' => $package->id]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertSame('Free Fire Global', $response->json('data.0.game.name'));
        $this->assertSame('100 Diamonds', $response->json('data.0.package.name'));
    }

    /** Founder-requested visibility: which brand's storefront this order came from. */
    public function test_index_lists_orders_with_the_affiliate_they_belong_to(): void
    {
        $affiliate = $this->primaryAffiliate();
        $this->order(['affiliate_id' => $affiliate->id]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertSame($affiliate->id, $response->json('data.0.affiliate.id'));
        $this->assertSame($affiliate->business_name, $response->json('data.0.affiliate.business_name'));
    }

    /** ADR-073 decision 5: which Reseller (wallet) account placed this order, for a wallet order. */
    public function test_index_lists_wallet_orders_with_the_reseller_that_placed_them(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme Reseller', 'is_active' => true]);
        $this->order(['wallet_reseller_id' => $reseller->id, 'payment_method' => 'wallet']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertSame($reseller->id, $response->json('data.0.wallet_reseller.id'));
        $this->assertSame('Acme Reseller', $response->json('data.0.wallet_reseller.business_name'));
    }

    public function test_index_wallet_reseller_is_null_for_a_normal_storefront_order(): void
    {
        $this->order();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertNull($response->json('data.0.wallet_reseller'));
    }

    public function test_index_can_filter_by_need_action(): void
    {
        $this->order(['order_number' => 'KRS-NEEDS-ACTION', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->order(['order_number' => 'KRS-DELIVERED', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=need_action');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-NEEDS-ACTION', $response->json('data.0.order_number'));
    }

    /**
     * ADR-108 decision 1 — a Paid+Failed order that already has a
     * compensation Voucher no longer counts as "need action": the
     * admin already resolved it, isAlreadyCompensated() already blocks
     * every further action on it, so it shouldn't still show up asking
     * for one. Confirmed live on production 2026-09-18: all 4 orders
     * in this bucket were already compensated, real actionable count
     * was 0, not 4.
     */
    public function test_index_need_action_excludes_an_order_with_a_compensation_voucher(): void
    {
        $order = $this->order(['order_number' => 'KRS-VOUCHERED', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        Voucher::query()->create([
            'order_id' => $order->id, 'affiliate_id' => $order->affiliate_id,
            'code' => 'KRS-COMP-TEST', 'customer_email' => 'buyer@example.com',
            'amount' => 500, 'remaining' => 500, 'status' => 'active', 'reason' => 'test',
        ]);
        $this->order(['order_number' => 'KRS-STILL-NEEDS-ACTION', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=need_action');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-STILL-NEEDS-ACTION', $response->json('data.0.order_number'));
    }

    /** ADR-108 decision 1 — same exclusion, wallet-refund compensation path. */
    public function test_index_need_action_excludes_an_order_already_refunded_to_wallet(): void
    {
        $reseller = $this->walletReseller();
        $order = $this->order([
            'order_number' => 'KRS-WALLET-REFUNDED', 'wallet_reseller_id' => $reseller->id,
            'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, $order->final_amount, 'wallet_refund', 'order', $order->id);
        $this->order(['order_number' => 'KRS-STILL-NEEDS-ACTION', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=need_action');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-STILL-NEEDS-ACTION', $response->json('data.0.order_number'));
    }

    /** ADR-108 decision 1 — same exclusion, restore-only path (ADR-024 addendum, no `voucher()` row at all). */
    public function test_index_need_action_excludes_an_order_with_a_restored_voucher_redemption(): void
    {
        $order = $this->order(['order_number' => 'KRS-RESTORED', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $paidWith = Voucher::query()->create([
            'affiliate_id' => $order->affiliate_id, 'code' => 'KRS-ORIGINAL', 'customer_email' => 'buyer@example.com',
            'amount' => 1000, 'remaining' => 1000, 'status' => 'active', 'reason' => 'test',
        ]);
        VoucherRedemption::query()->create([
            'voucher_id' => $paidWith->id, 'order_id' => $order->id, 'amount' => 1000, 'status' => 'restored',
        ]);
        $this->order(['order_number' => 'KRS-STILL-NEEDS-ACTION', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=need_action');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-STILL-NEEDS-ACTION', $response->json('data.0.order_number'));
    }

    /**
     * ADR-108 addendum 2026-10-09 — "failed" lists every failed delivery,
     * compensated or not (Reports' "View failed orders"), unlike
     * need_action, which drops the compensated ones.
     */
    public function test_index_failed_includes_compensated_orders(): void
    {
        $compensated = $this->order(['order_number' => 'KRS-FAILED-VOUCHERED', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        Voucher::query()->create([
            'order_id' => $compensated->id, 'affiliate_id' => $compensated->affiliate_id,
            'code' => 'KRS-COMP-FAILED', 'customer_email' => 'buyer@example.com',
            'amount' => 500, 'remaining' => 500, 'status' => 'active', 'reason' => 'test',
        ]);
        $this->order(['order_number' => 'KRS-FAILED-OPEN', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->order(['order_number' => 'KRS-DELIVERED', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=failed');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(['KRS-FAILED-VOUCHERED', 'KRS-FAILED-OPEN'], array_column($response->json('data'), 'order_number'));
    }

    /**
     * ADR-026 — needs_review is deliberately its own filter, distinct
     * from need_action: "Issue Voucher" is never available for these,
     * only "Mark as Delivered" or "Resend Delivery".
     */
    public function test_index_can_filter_by_needs_review(): void
    {
        $this->order(['order_number' => 'KRS-NEEDS-REVIEW', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::NeedsReview->value]);
        $this->order(['order_number' => 'KRS-NEEDS-ACTION', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=needs_review');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-NEEDS-REVIEW', $response->json('data.0.order_number'));
    }

    /** ADR-032: an async supplier accepted the order but hasn't confirmed the final outcome yet. */
    public function test_index_can_filter_by_pending_delivery(): void
    {
        $this->order(['order_number' => 'KRS-DELIVERY-PENDING', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Pending->value]);
        $this->order(['order_number' => 'KRS-PROCESSING-NOT-PENDING', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Processing->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=pending_delivery');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-DELIVERY-PENDING', $response->json('data.0.order_number'));
    }

    public function test_index_can_filter_by_processing(): void
    {
        $this->order(['order_number' => 'KRS-PROCESSING', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Processing->value]);
        $this->order(['order_number' => 'KRS-PENDING', 'payment_status' => PaymentStatus::Pending->value, 'delivery_status' => DeliveryStatus::NotStarted->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=processing');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-PROCESSING', $response->json('data.0.order_number'));
    }

    public function test_index_can_filter_by_completed(): void
    {
        $this->order(['order_number' => 'KRS-DONE', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $this->order(['order_number' => 'KRS-NOT-DONE', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Processing->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=completed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-DONE', $response->json('data.0.order_number'));
    }

    /**
     * ADR-021 (PAY-3) — surfaces an order whose payment webhook never
     * arrived. Matches the same 30-minute window
     * ReconcilePendingPaymentsCommand itself acts on.
     */
    public function test_index_can_filter_by_awaiting_payment(): void
    {
        $stuck = $this->order(['order_number' => 'KRS-STUCK-PENDING']);
        $stuck->forceFill(['created_at' => now()->subMinutes(45)])->save();
        $this->order(['order_number' => 'KRS-FRESH-PENDING']); // just checked out, webhook hasn't had time to arrive yet
        $this->order(['order_number' => 'KRS-ALREADY-PAID', 'payment_status' => PaymentStatus::Paid->value])
            ->forceFill(['created_at' => now()->subMinutes(45)])->save();

        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=awaiting_payment');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-STUCK-PENDING', $response->json('data.0.order_number'));
    }

    public function test_index_can_filter_by_today(): void
    {
        $today = $this->order(['order_number' => 'KRS-TODAY']);
        $yesterday = $this->order(['order_number' => 'KRS-YESTERDAY']);
        $yesterday->forceFill(['created_at' => now()->subDay()])->save();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=today');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-TODAY', $response->json('data.0.order_number'));
    }

    public function test_summary_returns_a_count_for_each_bucket(): void
    {
        // need_action
        $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        // needs_review
        $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::NeedsReview->value]);
        $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::NeedsReview->value]);
        // processing bucket = processing + pending_delivery combined (ADR-092 decision 2)
        $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Processing->value]);
        $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Pending->value]);
        // completed
        $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders/summary');

        $response->assertOk();
        $response->assertJson([
            'need_action' => 1,
            'needs_review' => 2,
            'processing' => 2,
            'completed' => 1,
            'today' => 6,
            'all' => 6,
        ]);
    }

    public function test_summary_today_excludes_orders_created_on_a_previous_day(): void
    {
        $this->order(['order_number' => 'KRS-TODAY']);
        $yesterday = $this->order(['order_number' => 'KRS-YESTERDAY']);
        $yesterday->forceFill(['created_at' => now()->subDay()])->save();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders/summary');

        $response->assertOk();
        $response->assertJson(['today' => 1, 'all' => 2]);
    }

    public function test_summary_excludes_sandbox_orders(): void
    {
        $this->order(['is_test' => true, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->order(['is_test' => false, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders/summary');

        $response->assertOk();
        $response->assertJson(['need_action' => 1, 'all' => 1]);
    }

    /**
     * ADR-108 decision 1 — the KPI card must agree with the tab it
     * labels: a compensated Paid+Failed order no longer counts, same
     * exclusion as the index() filter (same underlying scope, so they
     * can never drift out of sync with each other again).
     */
    public function test_summary_need_action_excludes_a_compensated_order(): void
    {
        $order = $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        Voucher::query()->create([
            'order_id' => $order->id, 'affiliate_id' => $order->affiliate_id,
            'code' => 'KRS-COMP-SUMMARY', 'customer_email' => 'buyer@example.com',
            'amount' => 500, 'remaining' => 500, 'status' => 'active', 'reason' => 'test',
        ]);
        $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders/summary');

        $response->assertOk();
        $response->assertJson(['need_action' => 1, 'all' => 2]);
    }

    public function test_index_can_search_by_order_number(): void
    {
        $this->order(['order_number' => 'KRS-FINDME', 'customer_email' => 'a@example.com']);
        $this->order(['order_number' => 'KRS-OTHER', 'customer_email' => 'b@example.com']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?search=FINDME');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_can_search_by_customer_email(): void
    {
        $this->order(['order_number' => 'KRS-A', 'customer_email' => 'findme@example.com']);
        $this->order(['order_number' => 'KRS-B', 'customer_email' => 'other@example.com']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?search=findme@example.com');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    /** ADR-108 decision 4 — search widened to match game name too, not just order#/email. */
    public function test_index_can_search_by_game_name(): void
    {
        $game = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my']);
        $this->order(['order_number' => 'KRS-A', 'game_id' => $game->id]);
        $this->order(['order_number' => 'KRS-B']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?search=Mobile Legends');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-A', $response->json('data.0.order_number'));
    }

    /** ADR-108 decision 5 — source filter: reseller (wallet) / affiliate (partner whitelabel) / direct (primary brand or no affiliate). */
    public function test_index_can_filter_by_source_reseller(): void
    {
        $reseller = $this->walletReseller();
        $this->order(['order_number' => 'KRS-RESELLER', 'wallet_reseller_id' => $reseller->id]);
        $this->order(['order_number' => 'KRS-DIRECT']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?source=reseller');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-RESELLER', $response->json('data.0.order_number'));
    }

    public function test_index_can_filter_by_source_affiliate(): void
    {
        $partner = Affiliate::query()->create(['business_name' => 'Acme Partner', 'markup_pct' => 5, 'status' => 'active', 'is_owned' => false]);
        $this->order(['order_number' => 'KRS-PARTNER', 'affiliate_id' => $partner->id]);
        $this->order(['order_number' => 'KRS-PRIMARY']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?source=affiliate');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-PARTNER', $response->json('data.0.order_number'));
    }

    public function test_index_can_filter_by_source_direct(): void
    {
        $partner = Affiliate::query()->create(['business_name' => 'Acme Partner', 'markup_pct' => 5, 'status' => 'active', 'is_owned' => false]);
        $this->order(['order_number' => 'KRS-PARTNER', 'affiliate_id' => $partner->id]);
        $this->order(['order_number' => 'KRS-PRIMARY']);
        $reseller = $this->walletReseller();
        $this->order(['order_number' => 'KRS-RESELLER', 'wallet_reseller_id' => $reseller->id]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?source=direct');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-PRIMARY', $response->json('data.0.order_number'));
    }

    /** ADR-108 decision 6 — game filter, exact game_id match. */
    public function test_index_can_filter_by_game_id(): void
    {
        $gameA = Game::query()->create(['name' => 'Game A', 'slug' => 'game-a']);
        $gameB = Game::query()->create(['name' => 'Game B', 'slug' => 'game-b']);
        $this->order(['order_number' => 'KRS-A', 'game_id' => $gameA->id]);
        $this->order(['order_number' => 'KRS-B', 'game_id' => $gameB->id]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/orders?game_id={$gameA->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-A', $response->json('data.0.order_number'));
    }

    /** ADR-108 decision 7 — date-range filter on created_at, reusing the Reports page's own preset shape (KL calendar-date 'YYYY-MM-DD', both inclusive). */
    public function test_index_can_filter_by_date_range(): void
    {
        $inRange = $this->order(['order_number' => 'KRS-IN-RANGE']);
        $inRange->forceFill(['created_at' => '2026-09-10 12:00:00'])->save();
        $outOfRange = $this->order(['order_number' => 'KRS-OUT-OF-RANGE']);
        $outOfRange->forceFill(['created_at' => '2026-09-01 12:00:00'])->save();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?date_from=2026-09-05&date_to=2026-09-15');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-IN-RANGE', $response->json('data.0.order_number'));
    }

    /** ADR-108 2026-10-04 addendum — the export is a 2-sheet workbook of the filtered orders. */
    public function test_export_downloads_a_workbook_of_matching_orders(): void
    {
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $this->order(['order_number' => 'KRS-EXPORT-ME', 'game_id' => $game->id, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $this->order(['order_number' => 'KRS-EXCLUDED', 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $response = $this->get('/api/orders/export?status=completed');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringEndsWith('.xlsx', (string) $response->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'export-test-');
        file_put_contents($path, file_get_contents($response->getFile()->getPathname()));
        $zip = new \ZipArchive;
        $zip->open($path);
        $orders = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $summary = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('KRS-EXPORT-ME', $orders);
        $this->assertStringNotContainsString('KRS-EXCLUDED', $orders);
        $this->assertStringContainsString('tab: completed', $summary);
    }

    /** ADR-108 2026-10-04 addendum — the date filter selects KL calendar days by created time. */
    public function test_date_filter_uses_kuala_lumpur_days(): void
    {
        $this->actingAsAdmin();
        $earlyKl = $this->order(['order_number' => 'KRS-KL-EARLY']);   // 4 Oct 01:00 KL
        $earlyKl->forceFill(['created_at' => '2026-10-03 17:00:00'])->save();
        $lateUtc = $this->order(['order_number' => 'KRS-KL-NEXT']);    // 5 Oct 01:00 KL
        $lateUtc->forceFill(['created_at' => '2026-10-04 17:00:00'])->save();

        $numbers = collect($this->getJson('/api/orders?date_from=2026-10-04&date_to=2026-10-04')->assertOk()->json('data'))->pluck('order_number');

        $this->assertSame(['KRS-KL-EARLY'], $numbers->all());
    }

    public function test_today_tab_and_kpi_use_the_kuala_lumpur_day(): void
    {
        $this->actingAsAdmin();
        $this->travelTo(Carbon::parse('2026-10-04 03:00:00', 'UTC')); // 11:00 KL, 4 Oct
        $this->order(['order_number' => 'KRS-TODAY-KL'])->forceFill(['created_at' => '2026-10-03 16:30:00'])->save();  // 00:30 KL 4 Oct
        $this->order(['order_number' => 'KRS-YESTERDAY-KL'])->forceFill(['created_at' => '2026-10-03 15:30:00'])->save(); // 23:30 KL 3 Oct

        $numbers = collect($this->getJson('/api/orders?status=today')->assertOk()->json('data'))->pluck('order_number');
        $this->assertSame(['KRS-TODAY-KL'], $numbers->all());
        $this->getJson('/api/orders/summary')->assertOk()->assertJsonPath('today', 1);
    }

    /**
     * ADR-018 decision #2: permanent, unconditional exclusion — a
     * sandbox order must never appear on this real, money-critical
     * screen, regardless of what status filter is applied.
     */
    public function test_index_never_includes_sandbox_orders(): void
    {
        $this->order(['order_number' => 'KRS-SANDBOX-1', 'is_test' => true]);
        $this->order(['order_number' => 'KRS-REAL-1', 'is_test' => false]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-REAL-1', $response->json('data.0.order_number'));
    }

    /** ADR-102 decision 12 — the list's compensation badges: cheap booleans, never an N+1 per row. */
    public function test_index_exposes_the_compensation_badges(): void
    {
        $this->actingAsAdmin();
        $reseller = $this->walletReseller();

        $plainOrder = $this->order(['delivery_status' => DeliveryStatus::Delivered->value]);

        $paidWith = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-USED-1',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);
        $usedVoucherOrder = $this->order(['voucher_id' => $paidWith->id, 'delivery_status' => DeliveryStatus::Delivered->value]);
        VoucherRedemption::query()->create(['voucher_id' => $paidWith->id, 'order_id' => $usedVoucherOrder->id, 'amount' => 500, 'status' => 'committed']);
        // ADR-024 2026-10-04 addendum decision 11: failed closed — voucher_id kept, nothing spent.
        $failedClosedOrder = $this->order(['voucher_id' => $paidWith->id, 'payment_status' => PaymentStatus::Failed->value]);

        $failedOrder = $this->order(['delivery_status' => DeliveryStatus::Failed->value]);
        Voucher::query()->create([
            'order_id' => $failedOrder->id,
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-ISSUED-1',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $walletRefundedOrder = $this->order([
            'wallet_reseller_id' => $reseller->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
            'final_amount' => 500,
        ]);
        $this->postJson("/api/orders/{$walletRefundedOrder->id}/refund-to-wallet")->assertOk();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertFalse((bool) $byId[$plainOrder->id]['has_used_voucher']);
        $this->assertFalse((bool) $byId[$plainOrder->id]['has_compensation_voucher']);
        $this->assertFalse((bool) $byId[$plainOrder->id]['has_wallet_refund']);
        $this->assertTrue((bool) $byId[$usedVoucherOrder->id]['has_used_voucher']);
        $this->assertFalse((bool) $byId[$failedClosedOrder->id]['has_used_voucher']);
        $this->assertTrue((bool) $byId[$failedOrder->id]['has_compensation_voucher']);
        $this->assertTrue((bool) $byId[$walletRefundedOrder->id]['has_wallet_refund']);
    }

    /**
     * ADR-024 addendum (2026-09-17, restore-only) — the 4th compensation
     * badge: true once this order's own voucher redemption is
     * 'restored', independent of whether a new compensation Voucher was
     * also minted (it wasn't, for a full-cover-by-voucher order).
     */
    public function test_index_and_show_expose_the_voucher_restored_badge(): void
    {
        $this->actingAsAdmin();
        $originalVoucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-RESTORED-1',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);
        $order = $this->order(['voucher_id' => $originalVoucher->id, 'delivery_status' => DeliveryStatus::Failed->value]);
        VoucherRedemption::query()->create([
            'voucher_id' => $originalVoucher->id,
            'order_id' => $order->id,
            'amount' => 500,
            'status' => 'restored',
        ]);
        $plainOrder = $this->order(['delivery_status' => DeliveryStatus::Delivered->value]);

        $show = $this->getJson("/api/orders/{$order->id}");
        $show->assertOk()->assertJsonPath('has_voucher_restored', true);

        $index = $this->getJson('/api/orders');
        $byId = collect($index->json('data'))->keyBy('id');
        $this->assertTrue((bool) $byId[$order->id]['has_voucher_restored']);
        $this->assertFalse((bool) $byId[$plainOrder->id]['has_voucher_restored']);
    }
}
