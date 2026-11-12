<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Voucher;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-036 2026-11-07 addendum (PRD §16 item 168): a voucher can't be closed
 * while an order may still give value back to it, and no-expiry credit
 * never gains an expiry through a merge.
 */
class VoucherCloseGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function voucher(int $remaining, array $overrides = []): Voucher
    {
        return Voucher::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'VC-'.uniqid(),
            'customer_email' => 'customer@example.com',
            'amount' => $remaining,
            'remaining' => $remaining,
            'status' => 'active',
            'reason' => 'test',
        ], $overrides));
    }

    private function orderUsing(Voucher $voucher, int $amount): Order
    {
        $order = Order::query()->create([
            'placed_via' => 'storefront', 'affiliate_id' => $voucher->affiliate_id, 'order_number' => 'PG-'.strtoupper(uniqid()),
            'customer_email' => $voucher->customer_email, 'player_id' => '123456', 'cost_price' => 900,
            'standard_selling_price' => 1000, 'selling_price' => 1000, 'voucher_id' => $voucher->id, 'voucher_discount' => $amount,
            'transaction_fee' => 0, 'final_amount' => 1000 - $amount, 'platform_profit' => 100, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value, 'delivery_status' => DeliveryStatus::NotStarted->value,
        ]);

        app(VoucherService::class)->redeem($voucher->id, $order->id, $amount, $voucher->customer_email, null);

        return $order;
    }

    private function merge(array $ids, ?string $expiresAt = null)
    {
        return $this->postJson('/api/vouchers/merge', ['voucher_ids' => $ids, 'reason' => 'Same customer', 'expires_at' => $expiresAt]);
    }

    public function test_a_voucher_an_order_is_using_cannot_be_merged(): void
    {
        $a = $this->voucher(1000);
        $b = $this->voucher(500);
        $order = $this->orderUsing($a, 400);

        $response = $this->merge([$a->id, $b->id]);

        $response->assertUnprocessable();
        $message = $response->json('errors.voucher_ids.0');
        $this->assertStringContainsString($order->order_number, $message);
        $this->assertStringContainsString($a->code, $message);
        $this->assertSame('active', $a->fresh()->status);
        $this->assertSame(600, $a->fresh()->remaining);
        $this->assertSame(2, Voucher::query()->count());
    }

    public function test_a_voucher_an_order_is_using_cannot_be_revoked(): void
    {
        $a = $this->voucher(1000, ['source' => Voucher::SOURCE_GIFT]);
        $order = $this->orderUsing($a, 400);

        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());
        $response = $this->patchJson("/api/vouchers/{$a->id}/revoke", ['reason' => 'Issued by mistake']);

        $response->assertUnprocessable();
        $this->assertStringContainsString($order->order_number, $response->json('errors.status.0'));
        $this->assertSame('active', $a->fresh()->status);
        $this->assertNull($a->fresh()->revoked_at);
    }

    public function test_once_the_order_gives_the_value_back_the_voucher_can_be_merged_whole(): void
    {
        $a = $this->voucher(1000);
        $b = $this->voucher(500);
        $order = $this->orderUsing($a, 400);
        app(VoucherService::class)->restore($order->id);

        $response = $this->merge([$a->id, $b->id]);

        $response->assertCreated();
        $response->assertJsonPath('remaining', 1500);
    }

    public function test_once_the_order_delivered_the_rest_of_the_voucher_can_be_merged(): void
    {
        $a = $this->voucher(1000);
        $b = $this->voucher(500);
        $order = $this->orderUsing($a, 400);
        app(VoucherService::class)->commit($order->id);

        $response = $this->merge([$a->id, $b->id]);

        $response->assertCreated();
        $response->assertJsonPath('remaining', 1100);
    }

    public function test_a_no_expiry_voucher_never_gains_an_expiry_through_a_merge(): void
    {
        $credit = $this->voucher(1000);
        $gift = $this->voucher(500, ['expires_at' => now()->addMonth()]);

        $this->merge([$credit->id, $gift->id], now()->addYear()->toDateString())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voucher_ids');

        $this->assertSame('active', $credit->fresh()->status);
    }

    public function test_a_merge_of_no_expiry_vouchers_stays_without_one(): void
    {
        $this->merge([$this->voucher(1000)->id, $this->voucher(500)->id])
            ->assertCreated()
            ->assertJsonPath('expires_at', null);
    }

    public function test_gifts_that_all_expire_can_still_take_a_new_date(): void
    {
        $date = now()->addMonths(2)->toDateString();

        $this->merge([
            $this->voucher(1000, ['expires_at' => now()->addMonth()])->id,
            $this->voucher(500, ['expires_at' => now()->addMonth()])->id,
        ], $date)->assertCreated();
    }

    public function test_the_stats_count_a_merge_once_and_only_real_spending_as_used(): void
    {
        $a = $this->voucher(1000);
        $b = $this->voucher(500);
        $target = $this->merge([$a->id, $b->id])->assertCreated()->json();
        $spent = Voucher::query()->findOrFail($target['id']);
        $this->orderUsing($spent, 400);

        $stats = $this->getJson('/api/vouchers')->assertOk()->json('stats');

        $this->assertSame(['count' => 2, 'total' => 1500], $stats['total_issued']);
        $this->assertSame(['count' => 1, 'total' => 400], $stats['total_used']);
        $this->assertSame(['count' => 1, 'total' => 1100], $stats['active']);
    }

    public function test_the_stats_leave_out_value_given_back(): void
    {
        $a = $this->voucher(1000);
        $order = $this->orderUsing($a, 400);
        app(VoucherService::class)->restore($order->id);

        $stats = $this->getJson('/api/vouchers')->assertOk()->json('stats');

        $this->assertSame(['count' => 0, 'total' => 0], $stats['total_used']);
    }
}
