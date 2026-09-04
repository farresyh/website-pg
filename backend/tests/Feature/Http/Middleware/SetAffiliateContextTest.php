<?php

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\SetAffiliateContext;
use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateUser;
use App\Models\Order;
use App\Support\CurrentAffiliate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ADR-058 (58a): the middleware that switches on ADR-057's tenant scope
 * for an affiliate-portal request.
 */
class SetAffiliateContextTest extends TestCase
{
    use RefreshDatabase;

    private function middleware(): SetAffiliateContext
    {
        return new SetAffiliateContext(app(CurrentAffiliate::class));
    }

    private function affiliate(string $name): Affiliate
    {
        return Affiliate::query()->create([
            'business_name' => $name,
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
    }

    private function requestFor(?object $user): Request
    {
        $request = Request::create('/api/affiliate/me', 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function orderFor(Affiliate $affiliate): void
    {
        Order::query()->create([
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
        ]);
    }

    public function test_it_activates_the_tenant_scope_from_the_users_affiliate_id(): void
    {
        $tenantA = $this->affiliate('Tenant A');
        $tenantB = $this->affiliate('Tenant B');
        $this->orderFor($tenantA);
        $this->orderFor($tenantB);

        $user = AffiliateUser::query()->create([
            'affiliate_id' => $tenantA->id,
            'name' => 'Staff',
            'email' => 'staff@a.test',
            'password' => 'x',
            'is_active' => true,
        ]);

        $seenInside = null;
        $response = $this->middleware()->handle($this->requestFor($user), function () use (&$seenInside) {
            $seenInside = Order::query()->pluck('affiliate_id')->all();

            return response('ok');
        });

        $this->assertSame('ok', $response->getContent());
        $this->assertSame([$tenantA->id], $seenInside);
        // Deactivated on the way out.
        $this->assertFalse(app(CurrentAffiliate::class)->isActive());
        $this->assertSame(2, Order::query()->count());
    }

    public function test_it_rejects_a_non_affiliate_user(): void
    {
        $admin = AdminUser::factory()->create();

        $this->expectException(HttpException::class);
        $this->middleware()->handle($this->requestFor($admin), fn () => response('ok'));
    }

    public function test_it_rejects_a_deactivated_affiliate_user(): void
    {
        $user = AffiliateUser::query()->create([
            'affiliate_id' => $this->affiliate('Tenant A')->id,
            'name' => 'Staff',
            'email' => 'staff@a.test',
            'password' => 'x',
            'is_active' => false,
        ]);

        $this->expectException(HttpException::class);
        $this->middleware()->handle($this->requestFor($user), fn () => response('ok'));
    }

    public function test_it_deactivates_the_context_even_when_the_next_handler_throws(): void
    {
        $user = AffiliateUser::query()->create([
            'affiliate_id' => $this->affiliate('Tenant A')->id,
            'name' => 'Staff',
            'email' => 'staff@a.test',
            'password' => 'x',
            'is_active' => true,
        ]);

        try {
            $this->middleware()->handle($this->requestFor($user), function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(app(CurrentAffiliate::class)->isActive());
    }
}
