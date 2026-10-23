<?php

namespace Tests\Support;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\WhatsAppGroup;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Joki\JokiCatalog;
use App\Services\Pricing\PricingBasis;

/**
 * ADR-126: the seeded joki game and pool, a penjoki, and a paid
 * Fixed-basis order waiting in the pool. Needs
 * OrderFulfillmentFixtures and OrderCredentialFixtures.
 */
trait JokiFixtures
{
    /** Joki set up as the admin's switch does it (JokiCatalog::ensure()). */
    private function jokiGame(): Game
    {
        return app(JokiCatalog::class)->ensure()->game;
    }

    private function jokiPackage(string $name = 'Joki Ranked'): Package
    {
        return Package::query()->firstOrCreate(['game_id' => $this->jokiGame()->id, 'name' => $name], [
            'cost_price' => 0, 'standard_selling_price' => 0,
            'supplier_id' => Supplier::assignmentPool()->id, 'supplier_package_ref' => 'JOKI-'.strtoupper(uniqid()),
        ]);
    }

    private function penjoki(string $slug, bool $withGroup = true, bool $active = true): Supplier
    {
        $supplier = Supplier::query()->create([
            'name' => "Penjoki {$slug}", 'slug' => "penjoki-{$slug}", 'driver' => Supplier::DRIVER_MANUAL,
            'funding_mode' => Supplier::FUNDING_PAY_AFTER, 'api_config' => [], 'currency' => 'MYR',
            'joki_role' => Supplier::JOKI_PENJOKI, 'is_active' => $active,
        ]);

        if ($withGroup) {
            WhatsAppGroup::query()->create(['owner_type' => 'supplier', 'owner_id' => $supplier->id, 'whatsapp_group_id' => "{$slug}@g.us"]);
        }

        return $supplier;
    }

    /** RM70 at a 100% share: payout RM70, platform profit RM0. */
    private function pendingJokiOrder(array $overrides = []): Order
    {
        $package = $this->jokiPackage();
        $order = $this->paidOrder(array_merge([
            'order_number' => 'PG-JOKI'.strtoupper(substr(uniqid(), -5)),
            'game_id' => $package->game_id, 'package_id' => $package->id,
            'supplier_id' => $package->supplier_id, 'supplier_product_ref' => $package->supplier_package_ref,
            'player_id' => 'player@example.com', 'server_id' => null,
            'pricing_basis' => PricingBasis::Fixed->value,
            'cost_price' => 7000, 'standard_selling_price' => 7000, 'selling_price' => 7000,
            'transaction_fee' => 100, 'final_amount' => 7100, 'platform_profit' => 0, 'affiliate_profit' => 0,
        ], $overrides));
        $this->credentialFor($order);

        return app(OrderFulfillmentService::class)->fulfill($order);
    }
}
