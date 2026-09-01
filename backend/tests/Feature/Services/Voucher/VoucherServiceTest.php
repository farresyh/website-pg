<?php

namespace Tests\Feature\Services\Voucher;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Voucher\InvalidVoucherException;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-024. redeem()/commit()/restore() are exercised against a real
 * Order row (voucher_redemptions.order_id is a real FK) — see
 * order() below, matching OrderFulfillmentServiceTest's own
 * minimal-Order-row convention.
 */
class VoucherServiceTest extends TestCase
{
    use RefreshDatabase;

    private function voucher(array $overrides = []): Voucher
    {
        return Voucher::query()->create(array_merge([
            'code' => 'KRS-TEST-'.uniqid(),
            'customer_email' => 'a@example.com',
            'customer_phone' => null,
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'failed order compensation',
        ], $overrides));
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'reseller_id' => $this->primaryReseller()->id,
            'order_number' => 'KRS-TEST-'.uniqid(),
            'customer_email' => 'a@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 0,
            'final_amount' => 1000,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    /**
     * Moved here from VoucherController during the 2026-08-24 audit
     * (was a private controller method) — proves the move preserved
     * the atomic Voucher-row + ledger-debit write (ADR-002).
     */
    public function test_issue_creates_voucher_and_debits_platform_ledger_atomically(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);

        $voucher = app(VoucherService::class)->issue(
            customerEmail: 'a@example.com',
            amount: 5_000,
            reason: 'Goodwill credit',
            expiresAt: null,
            createdBy: $admin->id,
            approvedBy: null,
        );

        $this->assertSame(5_000, $voucher->amount);
        $this->assertSame(5_000, $voucher->remaining);
        $this->assertSame('active', $voucher->status);
        $this->assertNotNull($voucher->code);
        $this->assertSame(-5_000, app(LedgerService::class)->balance('platform', null));
    }

    public function test_preview_caps_discount_at_remaining(): void
    {
        $this->voucher(['code' => 'KRS-PREVIEW-1', 'remaining' => 400]);

        $preview = app(VoucherService::class)->preview('KRS-PREVIEW-1', 'a@example.com', null, 1000);

        $this->assertSame(400, $preview->discountSen);
        $this->assertSame(0, $preview->remainingAfterSen);
    }

    public function test_preview_caps_discount_at_selling_price(): void
    {
        $this->voucher(['code' => 'KRS-PREVIEW-2', 'remaining' => 1000]);

        $preview = app(VoucherService::class)->preview('KRS-PREVIEW-2', 'a@example.com', null, 400);

        $this->assertSame(400, $preview->discountSen);
        $this->assertSame(600, $preview->remainingAfterSen);
    }

    public function test_preview_matches_on_phone_when_email_differs(): void
    {
        $this->voucher(['code' => 'KRS-PREVIEW-3', 'customer_email' => 'owner@example.com', 'customer_phone' => '0111234567']);

        $preview = app(VoucherService::class)->preview('KRS-PREVIEW-3', 'different@example.com', '0111234567', 1000);

        $this->assertSame(1000, $preview->discountSen);
    }

    public function test_preview_rejects_when_neither_email_nor_phone_matches(): void
    {
        $this->voucher(['code' => 'KRS-PREVIEW-4', 'customer_email' => 'owner@example.com', 'customer_phone' => '0111234567']);

        $this->expectException(InvalidVoucherException::class);

        app(VoucherService::class)->preview('KRS-PREVIEW-4', 'stranger@example.com', '0119999999', 1000);
    }

    public function test_preview_rejects_an_unknown_code_with_the_same_generic_message_as_ownership_mismatch(): void
    {
        $this->expectException(InvalidVoucherException::class);
        $this->expectExceptionMessage('This voucher code is not valid for this order.');

        app(VoucherService::class)->preview('KRS-DOES-NOT-EXIST', 'a@example.com', null, 1000);
    }

    public function test_redeem_decreases_remaining_and_creates_a_reserved_redemption(): void
    {
        $voucher = $this->voucher(['code' => 'KRS-REDEEM-1', 'remaining' => 1000]);
        $order = $this->order();

        app(VoucherService::class)->redeem($voucher->id, $order->id, 400, 'a@example.com', null);

        $this->assertSame(600, $voucher->fresh()->remaining);
        $this->assertSame('active', $voucher->fresh()->status);

        $redemption = VoucherRedemption::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($redemption);
        $this->assertSame(400, $redemption->amount);
        $this->assertSame('reserved', $redemption->status);
    }

    public function test_redeem_marks_voucher_exhausted_when_remaining_hits_zero(): void
    {
        $voucher = $this->voucher(['code' => 'KRS-REDEEM-2', 'remaining' => 500]);
        $order = $this->order();

        app(VoucherService::class)->redeem($voucher->id, $order->id, 500, 'a@example.com', null);

        $this->assertSame(0, $voucher->fresh()->remaining);
        $this->assertSame('exhausted', $voucher->fresh()->status);
    }

    public function test_rejects_redeem_exceeding_remaining_balance_and_leaves_remaining_untouched(): void
    {
        $voucher = $this->voucher(['code' => 'KRS-REDEEM-3', 'remaining' => 300]);
        $order = $this->order();

        $this->expectException(InvalidVoucherException::class);

        try {
            app(VoucherService::class)->redeem($voucher->id, $order->id, 400, 'a@example.com', null);
        } finally {
            $this->assertSame(300, $voucher->fresh()->remaining);
            $this->assertSame(0, VoucherRedemption::query()->where('order_id', $order->id)->count());
        }
    }

    public function test_rejects_redeem_when_voucher_not_active(): void
    {
        $voucher = $this->voucher(['code' => 'KRS-REDEEM-4', 'status' => 'revoked']);
        $order = $this->order();

        $this->expectException(InvalidVoucherException::class);

        app(VoucherService::class)->redeem($voucher->id, $order->id, 100, 'a@example.com', null);
    }

    /**
     * ADR-024 decision #1's own belt-and-suspenders note —
     * requestPayment() is reachable from both initiate() and resume().
     */
    public function test_redeem_is_idempotent_for_the_same_order(): void
    {
        $voucher = $this->voucher(['code' => 'KRS-REDEEM-5', 'remaining' => 1000]);
        $order = $this->order();

        $service = app(VoucherService::class);
        $service->redeem($voucher->id, $order->id, 400, 'a@example.com', null);
        $service->redeem($voucher->id, $order->id, 400, 'a@example.com', null);

        $this->assertSame(600, $voucher->fresh()->remaining);
        $this->assertSame(1, VoucherRedemption::query()->where('order_id', $order->id)->count());
    }

    public function test_commit_marks_a_reserved_redemption_committed_without_touching_remaining(): void
    {
        $voucher = $this->voucher(['code' => 'KRS-COMMIT-1', 'remaining' => 1000]);
        $order = $this->order();
        app(VoucherService::class)->redeem($voucher->id, $order->id, 400, 'a@example.com', null);

        app(VoucherService::class)->commit($order->id);

        $this->assertSame('committed', VoucherRedemption::query()->where('order_id', $order->id)->value('status'));
        $this->assertSame(600, $voucher->fresh()->remaining);
    }

    public function test_commit_is_a_noop_when_the_order_never_redeemed_a_voucher(): void
    {
        $order = $this->order();

        app(VoucherService::class)->commit($order->id);

        $this->assertSame(0, VoucherRedemption::query()->where('order_id', $order->id)->count());
    }

    public function test_restore_gives_back_remaining_and_reactivates_an_exhausted_voucher(): void
    {
        $voucher = $this->voucher(['code' => 'KRS-RESTORE-1', 'remaining' => 400]);
        $order = $this->order();
        app(VoucherService::class)->redeem($voucher->id, $order->id, 400, 'a@example.com', null);
        $this->assertSame('exhausted', $voucher->fresh()->status);

        app(VoucherService::class)->restore($order->id);

        $this->assertSame(400, $voucher->fresh()->remaining);
        $this->assertSame('active', $voucher->fresh()->status);
        $this->assertSame('restored', VoucherRedemption::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_restore_is_a_noop_when_the_order_never_redeemed_a_voucher(): void
    {
        $order = $this->order();

        app(VoucherService::class)->restore($order->id);

        $this->assertSame(0, VoucherRedemption::query()->where('order_id', $order->id)->count());
    }

    public function test_restore_does_not_reactivate_a_voucher_the_admin_separately_revoked(): void
    {
        $voucher = $this->voucher(['code' => 'KRS-RESTORE-2', 'remaining' => 1000]);
        $order = $this->order();
        app(VoucherService::class)->redeem($voucher->id, $order->id, 400, 'a@example.com', null);
        $voucher->update(['status' => 'revoked']);

        app(VoucherService::class)->restore($order->id);

        // The balance is still credited back for accounting accuracy,
        // but a revoked voucher stays revoked — redeem()'s own status
        // guard keeps it un-spendable either way.
        $this->assertSame(1000, $voucher->fresh()->remaining);
        $this->assertSame('revoked', $voucher->fresh()->status);
    }
}
