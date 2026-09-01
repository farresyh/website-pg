<?php

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\SetResellerContext;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Support\CurrentReseller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ADR-058 (58a): the middleware that switches on ADR-057's tenant scope
 * for a reseller-portal request.
 */
class SetResellerContextTest extends TestCase
{
    use RefreshDatabase;

    private function middleware(): SetResellerContext
    {
        return new SetResellerContext(app(CurrentReseller::class));
    }

    private function reseller(string $name): Reseller
    {
        return Reseller::query()->create([
            'business_name' => $name,
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
    }

    private function requestFor(?object $user): Request
    {
        $request = Request::create('/api/reseller/me', 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function orderFor(Reseller $reseller): void
    {
        Order::query()->create([
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
        ]);
    }

    public function test_it_activates_the_tenant_scope_from_the_users_reseller_id(): void
    {
        $tenantA = $this->reseller('Tenant A');
        $tenantB = $this->reseller('Tenant B');
        $this->orderFor($tenantA);
        $this->orderFor($tenantB);

        $user = ResellerUser::query()->create([
            'reseller_id' => $tenantA->id,
            'name' => 'Staff',
            'email' => 'staff@a.test',
            'password' => 'x',
            'is_active' => true,
        ]);

        $seenInside = null;
        $response = $this->middleware()->handle($this->requestFor($user), function () use (&$seenInside) {
            $seenInside = Order::query()->pluck('reseller_id')->all();

            return response('ok');
        });

        $this->assertSame('ok', $response->getContent());
        $this->assertSame([$tenantA->id], $seenInside);
        // Deactivated on the way out.
        $this->assertFalse(app(CurrentReseller::class)->isActive());
        $this->assertSame(2, Order::query()->count());
    }

    public function test_it_rejects_a_non_reseller_user(): void
    {
        $admin = AdminUser::factory()->create();

        $this->expectException(HttpException::class);
        $this->middleware()->handle($this->requestFor($admin), fn () => response('ok'));
    }

    public function test_it_rejects_a_deactivated_reseller_user(): void
    {
        $user = ResellerUser::query()->create([
            'reseller_id' => $this->reseller('Tenant A')->id,
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
        $user = ResellerUser::query()->create([
            'reseller_id' => $this->reseller('Tenant A')->id,
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

        $this->assertFalse(app(CurrentReseller::class)->isActive());
    }
}
