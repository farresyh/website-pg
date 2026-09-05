<?php

namespace Tests\Feature\Database;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateFooterSettings;
use App\Models\AffiliateSeoSettings;
use App\Models\Order;
use App\Models\Redirect;
use App\Support\CurrentAffiliate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-057 decision 6: the tenant-isolation retrofit, proven per
 * newly-scoped model — (a) an affiliate session sees only its own rows,
 * (b) an admin / no-context session sees all, (c) a queue job sees all,
 * (d) an affiliate session with no resolvable tenant sees NONE (fail
 * closed, decision 3). Plus the withoutAffiliateScope() / runWithout()
 * escape hatches (decision 5).
 */
class AffiliateScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<class-string<Model>> */
    private const SCOPED_MODELS = [
        Order::class,
        AffiliateBranding::class,
        AffiliateFooterSettings::class,
        AffiliateSeoSettings::class,
        Redirect::class,
    ];

    private Affiliate $tenantA;

    private Affiliate $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->makeAffiliate('Tenant A');
        $this->tenantB = $this->makeAffiliate('Tenant B');

        // Rows are seeded with the scope inactive (the default), so setup
        // itself is never tenant-constrained.
        foreach ([$this->tenantA, $this->tenantB] as $affiliate) {
            foreach (self::SCOPED_MODELS as $model) {
                $this->seedRow($model, $affiliate);
            }
        }
    }

    public function test_a_affiliate_session_sees_only_its_own_rows(): void
    {
        $this->currentAffiliate()->activate($this->tenantA->id);

        foreach (self::SCOPED_MODELS as $model) {
            $rows = $model::query()->get();

            $this->assertCount(1, $rows, $model);
            $this->assertSame($this->tenantA->id, (int) $rows->first()->affiliate_id, $model);
        }
    }

    public function test_no_context_sees_all_rows(): void
    {
        // CurrentAffiliate left inactive — the admin panel, the console,
        // a queue job. No constraint applied.
        foreach (self::SCOPED_MODELS as $model) {
            $this->assertSame(2, $model::query()->count(), $model);
        }
    }

    public function test_a_affiliate_session_with_no_resolvable_tenant_sees_nothing(): void
    {
        $this->currentAffiliate()->activate(null);

        foreach (self::SCOPED_MODELS as $model) {
            $this->assertSame(0, $model::query()->count(), $model);
        }
    }

    public function test_without_affiliate_scope_bypasses_the_constraint(): void
    {
        $this->currentAffiliate()->activate($this->tenantA->id);

        foreach (self::SCOPED_MODELS as $model) {
            $this->assertSame(2, $model::withoutAffiliateScope()->count(), $model);
        }
    }

    public function test_run_without_bypasses_the_constraint_for_a_block(): void
    {
        $current = $this->currentAffiliate();
        $current->activate($this->tenantA->id);

        $count = $current->runWithout(fn () => Order::query()->count());

        $this->assertSame(2, $count);
        // Bypass is restored on exit — nesting-safe.
        $this->assertSame(1, Order::query()->count());
    }

    public function test_deactivate_restores_the_unconstrained_view(): void
    {
        $current = $this->currentAffiliate();
        $current->activate($this->tenantB->id);
        $this->assertSame(1, Order::query()->count());

        $current->deactivate();
        $this->assertSame(2, Order::query()->count());
    }

    private function currentAffiliate(): CurrentAffiliate
    {
        return app(CurrentAffiliate::class);
    }

    private function makeAffiliate(string $name): Affiliate
    {
        return Affiliate::query()->create([
            'business_name' => $name,
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
    }

    /** @param  class-string<Model>  $model */
    private function seedRow(string $model, Affiliate $affiliate): void
    {
        $model::query()->create(match ($model) {
            Order::class => [
                'order_number' => 'KRS-'.$affiliate->id.'-'.uniqid(),
                'customer_email' => 'buyer@example.com',
                'player_id' => '123456',
                'cost_price' => 900,
                'standard_selling_price' => 900,
                'selling_price' => 1000,
                'transaction_fee' => 90,
                'final_amount' => 1090,
                'platform_profit' => 100,
                'affiliate_profit' => 0,
                'affiliate_id' => $affiliate->id,
            ],
            Redirect::class => [
                'affiliate_id' => $affiliate->id,
                'from_path' => '/from-'.$affiliate->id,
                'to_path' => '/to-'.$affiliate->id,
                'status_code' => 301,
            ],
            AffiliateBranding::class => [
                'affiliate_id' => $affiliate->id,
                'store_name' => 'Store '.$affiliate->id,
            ],
            default => ['affiliate_id' => $affiliate->id],
        });
    }
}
