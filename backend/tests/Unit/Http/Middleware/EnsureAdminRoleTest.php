<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\EnsureAdminRole;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRoleTest extends TestCase
{
    private function requestAs(?AdminUser $admin): Request
    {
        $request = Request::create('/test');
        $request->setUserResolver(fn () => $admin);

        return $request;
    }

    public function test_allows_when_role_matches(): void
    {
        $admin = new AdminUser(['role' => 'super_admin', 'is_active' => true]);

        $response = (new EnsureAdminRole())->handle(
            $this->requestAs($admin),
            fn () => new Response('ok'),
            'super_admin',
        );

        $this->assertSame('ok', $response->getContent());
    }

    public function test_allows_when_role_matches_one_of_several_permitted_roles(): void
    {
        $admin = new AdminUser(['role' => 'admin', 'is_active' => true]);

        $response = (new EnsureAdminRole())->handle(
            $this->requestAs($admin),
            fn () => new Response('ok'),
            'super_admin',
            'admin',
        );

        $this->assertSame('ok', $response->getContent());
    }

    public function test_rejects_when_role_does_not_match(): void
    {
        $admin = new AdminUser(['role' => 'admin', 'is_active' => true]);

        $this->expectException(HttpException::class);

        (new EnsureAdminRole())->handle(
            $this->requestAs($admin),
            fn () => new Response('ok'),
            'super_admin',
        );
    }

    /**
     * Deactivation must block access even when the role itself matches
     * — an admin token doesn't stop working the instant is_active
     * flips, otherwise a deactivated account keeps full access until
     * its token happens to expire.
     */
    public function test_rejects_when_account_is_inactive(): void
    {
        $admin = new AdminUser(['role' => 'super_admin', 'is_active' => false]);

        $this->expectException(HttpException::class);

        (new EnsureAdminRole())->handle(
            $this->requestAs($admin),
            fn () => new Response('ok'),
            'super_admin',
        );
    }

    public function test_rejects_when_no_admin_is_authenticated(): void
    {
        $this->expectException(HttpException::class);

        (new EnsureAdminRole())->handle(
            $this->requestAs(null),
            fn () => new Response('ok'),
            'super_admin',
        );
    }
}
