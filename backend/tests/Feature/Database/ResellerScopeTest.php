<?php

namespace Tests\Feature\Database;

use App\Models\Order;
use App\Models\Redirect;
use App\Models\Reseller;
use App\Models\ResellerBranding;
use App\Models\ResellerFooterSettings;
use App\Models\ResellerSeoSettings;
use App\Support\CurrentReseller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-057 decision 6: the tenant-isolation retrofit, proven per
 * newly-scoped model — (a) a reseller session sees only its own rows,
 * (b) an admin / no-context session sees all, (c) a queue job sees all,
 * (d) a reseller session with no resolvable tenant sees NONE (fail
 * closed, decision 3). Plus the withoutResellerScope() / runWithout()
 * escape hatches (decision 5).
 */
class ResellerScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<class-string<Model>> */
    private const SCOPED_MODELS = [
        Order::class,
        ResellerBranding::class,
        ResellerFooterSettings::class,
        ResellerSeoSettings::class,
        Redirect::class,
    ];

    private Reseller $tenantA;

    private Reseller $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->makeReseller('Tenant A');
        $this->tenantB = $this->makeReseller('Tenant B');

        // Rows are seeded with the scope inactive (the default), so setup
        // itself is never tenant-constrained.
        foreach ([$this->tenantA, $this->tenantB] as $reseller) {
            foreach (self::SCOPED_MODELS as $model) {
                $this->seedRow($model, $reseller);
            }
        }
    }

    public function test_a_reseller_session_sees_only_its_own_rows(): void
    {
        $this->currentReseller()->activate($this->tenantA->id);

        foreach (self::SCOPED_MODELS as $model) {
            $rows = $model::query()->get();

            $this->assertCount(1, $rows, $model);
            $this->assertSame($this->tenantA->id, (int) $rows->first()->reseller_id, $model);
        }
    }

    public function test_no_context_sees_all_rows(): void
    {
        // CurrentReseller left inactive — the admin panel, the console,
        // a queue job. No constraint applied.
        foreach (self::SCOPED_MODELS as $model) {
            $this->assertSame(2, $model::query()->count(), $model);
        }
    }

    public function test_a_reseller_session_with_no_resolvable_tenant_sees_nothing(): void
    {
        $this->currentReseller()->activate(null);

        foreach (self::SCOPED_MODELS as $model) {
            $this->assertSame(0, $model::query()->count(), $model);
        }
    }

    public function test_without_reseller_scope_bypasses_the_constraint(): void
    {
        $this->currentReseller()->activate($this->tenantA->id);

        foreach (self::SCOPED_MODELS as $model) {
            $this->assertSame(2, $model::withoutResellerScope()->count(), $model);
        }
    }

    public function test_run_without_bypasses_the_constraint_for_a_block(): void
    {
        $current = $this->currentReseller();
        $current->activate($this->tenantA->id);

        $count = $current->runWithout(fn () => Order::query()->count());

        $this->assertSame(2, $count);
        // Bypass is restored on exit — nesting-safe.
        $this->assertSame(1, Order::query()->count());
    }

    public function test_deactivate_restores_the_unconstrained_view(): void
    {
        $current = $this->currentReseller();
        $current->activate($this->tenantB->id);
        $this->assertSame(1, Order::query()->count());

        $current->deactivate();
        $this->assertSame(2, Order::query()->count());
    }

    private function currentReseller(): CurrentReseller
    {
        return app(CurrentReseller::class);
    }

    private function makeReseller(string $name): Reseller
    {
        return Reseller::query()->create([
            'business_name' => $name,
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
    }

    /** @param  class-string<Model>  $model */
    private function seedRow(string $model, Reseller $reseller): void
    {
        $model::query()->create(match ($model) {
            Order::class => [
                'order_number' => 'KRS-'.$reseller->id.'-'.uniqid(),
                'customer_email' => 'buyer@example.com',
                'player_id' => '123456',
                'cost_price' => 900,
                'standard_selling_price' => 900,
                'selling_price' => 1000,
                'transaction_fee' => 90,
                'final_amount' => 1090,
                'platform_profit' => 100,
                'reseller_profit' => 0,
                'reseller_id' => $reseller->id,
            ],
            Redirect::class => [
                'reseller_id' => $reseller->id,
                'from_path' => '/from-'.$reseller->id,
                'to_path' => '/to-'.$reseller->id,
                'status_code' => 301,
            ],
            ResellerBranding::class => [
                'reseller_id' => $reseller->id,
                'store_name' => 'Store '.$reseller->id,
            ],
            default => ['reseller_id' => $reseller->id],
        });
    }
}
