<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Reseller;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * ADR-061 decision 9 (PR-B): the first `Order` factory. Built now
 * because `orders.reseller_id` is NOT NULL from this PR on — a bare
 * `Order::create` with no reseller now fails — and because ADR-059/060
 * tests will lean on it.
 *
 * Defaults deliberately describe a plain paid, undelivered guest order
 * priced with zero reseller markup (the primary brand's default), so a
 * test that cares about a state names it explicitly. `reseller_id`
 * resolves the single `is_primary` row, creating it if a test never
 * did — same shape as `Tests\TestCase::primaryReseller()`.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $costPrice = 900;
        $sellingPrice = 1000;
        $transactionFee = 100;

        return [
            'order_number' => 'PG-'.strtoupper(Str::random(12)),
            'reseller_id' => fn () => Reseller::query()->firstOrCreate(
                ['is_primary' => true],
                [
                    'business_name' => 'PekanGame', // ADR-062
                    'markup_pct' => 0,
                    'status' => 'active',
                    'is_owned' => true,
                    'membership_enabled' => true,
                ],
            )->id,
            'customer_email' => fake()->safeEmail(),
            'customer_name' => fake()->name(),
            'player_id' => (string) fake()->numberBetween(100000, 999999),
            'cost_price' => $costPrice,
            'standard_selling_price' => $sellingPrice,
            'selling_price' => $sellingPrice,
            'transaction_fee' => $transactionFee,
            'final_amount' => $sellingPrice + $transactionFee,
            'platform_profit' => $sellingPrice - $costPrice,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid,
            'delivery_status' => DeliveryStatus::NotStarted,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['payment_status' => PaymentStatus::Pending]);
    }

    public function delivered(): static
    {
        return $this->state(fn () => ['delivery_status' => DeliveryStatus::Delivered]);
    }

    public function forReseller(Reseller $reseller): static
    {
        return $this->state(fn () => ['reseller_id' => $reseller->id]);
    }
}
