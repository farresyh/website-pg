<?php

namespace Tests\Feature\Rebate;

use App\Models\AffiliateUser;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerRebateLadder;
use App\Models\ResellerTier;
use App\Models\ResellerWalletTierChange;
use App\Models\WhatsAppGroup;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Support\Facades\Hash;

/** One SS-tier reseller with a portal login, a WhatsApp group and a two-step ladder (ADR-139 PR 4 tests). */
trait RebateFixtures
{
    protected ResellerTier $ss;

    protected Reseller $reseller;

    protected AffiliateUser $user;

    protected function seedRebateWorld(string $ladderFrom = '2026-12-01'): void
    {
        $this->ss = ResellerTier::query()->create(['name' => 'Tier SS', 'markup_percent' => 3, 'is_active' => true, 'sort_order' => 1]);
        $this->reseller = $this->rebateReseller('Acme', $this->ss);
        $this->user = AffiliateUser::query()->create([
            'owner_type' => AccountOwnerType::Reseller->value, 'owner_id' => $this->reseller->id,
            'name' => 'Ali', 'email' => 'ali@example.com', 'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);
        ResellerRebateLadder::query()->create(['effective_from' => $ladderFrom, 'max_share_bp' => 3500, 'steps' => [
            ['threshold_sen' => 50000, 'rewards' => [(string) $this->ss->id => 100]],
            ['threshold_sen' => 150000, 'rewards' => [(string) $this->ss->id => 600]],
        ]]);
    }

    protected function rebateReseller(string $name, ResellerTier $tier, string $group = 'acme@g.us'): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => $name, 'is_active' => true, 'reseller_tier_id' => $tier->id]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        ResellerWalletTierChange::query()->create(['reseller_id' => $reseller->id, 'to_tier_id' => $tier->id, 'effective_on' => '2026-09-01', 'applied_at' => now()]);
        WhatsAppGroup::query()->create(['owner_type' => WhatsAppGroup::OWNER_RESELLER, 'owner_id' => $reseller->id, 'whatsapp_group_id' => $group, 'is_active' => true]);

        return $reseller;
    }

    protected function delivered(int $sellingSen, int $profitSen, string $recognisedAtUtc = '2026-12-10 04:00:00', ?Reseller $reseller = null): Order
    {
        return Order::query()->create([
            'placed_via' => 'reseller_api', 'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(), 'customer_email' => 'buyer@example.com', 'player_id' => '1',
            'wallet_reseller_id' => ($reseller ?? $this->reseller)->id,
            'cost_price' => $sellingSen - $profitSen, 'standard_selling_price' => $sellingSen, 'selling_price' => $sellingSen,
            'transaction_fee' => 0, 'final_amount' => $sellingSen, 'platform_profit' => $profitSen, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value,
            'recognised_at' => $recognisedAtUtc,
        ]);
    }

    protected function portalToken(): string
    {
        return $this->user->createToken('affiliate')->plainTextToken;
    }
}
