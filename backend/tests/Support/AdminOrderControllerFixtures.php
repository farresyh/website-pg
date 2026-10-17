<?php

namespace Tests\Support;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\Order;
use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Laravel\Sanctum\Sanctum;

/** Shared setup and helpers for the OrderControllerTest split (one file per feature). */
trait AdminOrderControllerFixtures
{
    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    /**
     * ADR-105 2026-10-06 decision 12 — the controller asks the same
     * preflight() as the job, so a loss on a tier-affiliate, reseller
     * wallet or member order is caught here too (it used to check the
     * Standard basis only, and the job then refused it silently).
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function lossOnEveryBasis(): array
    {
        return [
            'tier affiliate' => [['pricing_basis' => 'affiliate', 'wholesale_markup_pct' => 10.00, 'affiliate_markup_pct' => 5.00, 'selling_price' => 1040, 'affiliate_profit' => 50]],
            'reseller wallet' => [['pricing_basis' => 'reseller-wallet', 'wholesale_markup_pct' => 10.00, 'selling_price' => 990]],
            'member' => [['pricing_basis' => 'member', 'member_discount_percent' => 80.00, 'markup_percent' => 15.00, 'selling_price' => 927]],
        ];
    }

    /** ADR-073 decision 7: makes a wallet Reseller + its ledger account, mirrors order()'s helper shape. */
    private function walletReseller(): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme Reseller', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }
}
