<?php

namespace Database\Seeders;

use App\Models\Affiliate;
use App\Models\Game;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Services\Ledger\LedgerService;
use App\Services\Membership\MembershipStatus;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Pricing\PricingBasis;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEV ONLY — never part of DatabaseSeeder's default chain, never
 * run against production. Populates ~95 days of realistic-looking paid
 * orders (games, payment methods, member vs standard pricing, a wallet
 * Reseller) purely so `/admin/reports` has something to look at while
 * developing/reviewing it — see ADR-086. Run explicitly:
 *
 *   php artisan db:seed --class=Database\\Seeders\\ReportDemoSeeder
 *
 * Additive and re-runnable: reuses existing Games/Affiliates/Packages
 * where they already exist (`firstOrCreate`), only ever *adds* new
 * Order rows — never touches or deletes anything. Every order goes
 * through the same two-step "create Order, then LedgerService::credit()
 * the recognized profit" pattern the test suite uses (see
 * ReportServiceTest::order()) — never hand-writes a ledger_entries row
 * directly, so the double-count-safe invariant ADR-086 PR-1 locked in
 * holds here too.
 */
class ReportDemoSeeder extends Seeder
{
    private LedgerService $ledger;

    public function run(): void
    {
        $this->ledger = new LedgerService;

        $primaryAffiliate = Affiliate::query()->orderBy('id')->first()
            ?? Affiliate::query()->create(['business_name' => 'PekanGame', 'markup_pct' => 0]);

        $secondAffiliate = Affiliate::query()->where('id', '!=', $primaryAffiliate->id)->first()
            ?? Affiliate::query()->create(['business_name' => 'Demo Affiliate', 'markup_pct' => 8]);

        $reseller = Reseller::query()->firstOrCreate(
            ['business_name' => 'Demo Wallet Reseller'],
            ['is_active' => true],
        );

        $membershipPlan = MembershipPlan::query()->orderBy('id')->first();
        $membership = $membershipPlan !== null
            ? Membership::query()->firstOrCreate(
                ['email' => 'member-demo@example.com'],
                [
                    'affiliate_id' => $primaryAffiliate->id,
                    'membership_plan_id' => $membershipPlan->id,
                    'status' => MembershipStatus::Active,
                    'cycle_started_at' => now()->subDays(10),
                    'quota_remaining_sen' => $membershipPlan->quota_sen,
                    'expires_at' => now()->addDays(20),
                ],
            )
            : null;

        $packages = $this->ensureCatalog();
        $paymentMethods = ['fpx', 'card', 'duitnow_qr'];

        $this->command?->info('Seeding ~95 days of demo orders for /admin/reports...');

        $ordersCreated = 0;

        for ($daysAgo = 94; $daysAgo >= 0; $daysAgo--) {
            $day = CarbonImmutable::now('Asia/Kuala_Lumpur')->subDays($daysAgo);
            // Busier on recent days, quieter further back — a flat line is a boring demo.
            $ordersToday = random_int(0, $daysAgo > 60 ? 2 : ($daysAgo > 20 ? 4 : 6));

            for ($i = 0; $i < $ordersToday; $i++) {
                $this->seedOneOrder($day, $packages, $paymentMethods, $primaryAffiliate, $secondAffiliate, $reseller, $membership);
                $ordersCreated++;
            }
        }

        $this->command?->info("Done — {$ordersCreated} demo orders created.");
    }

    /** @return array<int, Package> */
    private function ensureCatalog(): array
    {
        $catalog = [
            ['game' => 'Mobile Legends', 'slug' => 'mobile-legends', 'package' => '278 Diamonds', 'cost' => 1450, 'price' => 1699],
            ['game' => 'PUBG Mobile', 'slug' => 'pubg-mobile', 'package' => '325 UC', 'cost' => 1900, 'price' => 2199],
            ['game' => 'Free Fire', 'slug' => 'free-fire', 'package' => '355 Diamonds', 'cost' => 1100, 'price' => 1299],
        ];

        $packages = Package::query()->where('is_active', true)->get()->all();
        $supplierId = Supplier::query()->value('id');

        foreach ($catalog as $entry) {
            $game = Game::query()->firstOrCreate(
                ['slug' => $entry['slug']],
                ['name' => $entry['game'], 'category' => 'Mobile', 'is_active' => true],
            );

            $package = Package::query()->firstOrCreate(
                ['game_id' => $game->id, 'name' => $entry['package']],
                [
                    'cost_price' => $entry['cost'],
                    'standard_selling_price' => $entry['price'],
                    'markup_percent' => 15,
                    'is_active' => true,
                    'sort_order' => 0,
                    'supplier_id' => $supplierId,
                    'supplier_package_ref' => 'demo-'.$entry['slug'],
                ],
            );

            $packages[] = $package;
        }

        return $packages;
    }

    /** @param  array<int, Package>  $packages */
    private function seedOneOrder(
        CarbonImmutable $day,
        array $packages,
        array $paymentMethods,
        Affiliate $primaryAffiliate,
        Affiliate $secondAffiliate,
        Reseller $reseller,
        ?Membership $membership,
    ): void {
        $package = $packages[array_rand($packages)];
        $costPrice = $package->cost_price;
        $standardPrice = $package->standard_selling_price;

        // Channel mix: mostly ordinary storefront (primary/second affiliate),
        // ~12% wallet-reseller, occasional member-priced order.
        $roll = random_int(1, 100);
        $isWalletReseller = $roll <= 12;
        $isMember = ! $isWalletReseller && $roll > 12 && $roll <= 22 && $membership !== null;

        $affiliateId = $isWalletReseller ? $primaryAffiliate->id : (random_int(0, 3) === 0 ? $secondAffiliate->id : $primaryAffiliate->id);
        $walletResellerId = $isWalletReseller ? $reseller->id : null;

        $sellingPrice = $isMember
            ? (int) round($standardPrice * 0.7) // Tier 1's 50% discount, applied loosely for demo variety
            : $standardPrice;
        $transactionFee = (int) round($sellingPrice * 0.02);
        $finalAmount = $sellingPrice + $transactionFee;
        $platformProfit = $sellingPrice - $costPrice;
        $affiliateProfit = ($affiliateId === $secondAffiliate->id && ! $isWalletReseller) ? (int) round($platformProfit * 0.15) : 0;
        $platformProfit -= $affiliateProfit;

        // Delivery outcome: 90% delivered (ledger-recognized profit), 7%
        // failed (paid, $0 profit — exercises ADR-086's pinned rule),
        // 3% still pending.
        $outcomeRoll = random_int(1, 100);
        $paymentStatus = $outcomeRoll <= 97 ? PaymentStatus::Paid : PaymentStatus::Pending;
        $deliveryStatus = match (true) {
            $paymentStatus === PaymentStatus::Pending => DeliveryStatus::NotStarted,
            $outcomeRoll <= 90 => DeliveryStatus::Delivered,
            default => DeliveryStatus::Failed,
        };

        $paidAt = $paymentStatus === PaymentStatus::Paid
            ? $day->addHours(random_int(8, 23))->addMinutes(random_int(0, 59))->setTimezone('UTC')
            : null;

        $order = Order::query()->create([
            'order_number' => 'DEMO-'.$day->format('Ymd').'-'.random_int(100000, 999999),
            'is_test' => false,
            'customer_email' => 'demo-customer-'.random_int(1, 40).'@example.com',
            'player_id' => (string) random_int(100000000, 999999999),
            'game_id' => $package->game_id,
            'package_id' => $package->id,
            'affiliate_id' => $affiliateId,
            'wallet_reseller_id' => $walletResellerId,
            'pricing_basis' => $isMember ? PricingBasis::Member : PricingBasis::Standard,
            'membership_id' => $isMember ? $membership->id : null,
            'normal_selling_price' => $isMember ? $standardPrice : null,
            'cost_price' => $costPrice,
            'standard_selling_price' => $standardPrice,
            'selling_price' => $sellingPrice,
            'transaction_fee' => $transactionFee,
            'final_amount' => $finalAmount,
            'platform_profit' => $platformProfit,
            'affiliate_profit' => $affiliateProfit,
            'payment_status' => $paymentStatus,
            'paid_at' => $paidAt,
            'delivery_status' => $deliveryStatus,
            'payment_method' => $paymentMethods[array_rand($paymentMethods)],
        ]);

        // Ledger-recognized profit only on an actual successful delivery —
        // ADR-086's pinned rule, mirrored here exactly like the real
        // fulfillment flow / the test suite's own fixture helper.
        if ($deliveryStatus === DeliveryStatus::Delivered) {
            $this->ledger->credit('platform', null, $platformProfit, 'order_profit', 'order', $order->id);

            if ($affiliateProfit > 0) {
                $this->ledger->credit('affiliate', $affiliateId, $affiliateProfit, 'order_profit', 'order', $order->id);
            }
        }
    }
}
