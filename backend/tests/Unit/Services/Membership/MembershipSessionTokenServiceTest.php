<?php

namespace Tests\Unit\Services\Membership;

use App\Services\Membership\MembershipSessionTokenService;
use Tests\TestCase;

/**
 * ADR-027's 2026-08-29 addendum, decisions 16/17/23: a stateless,
 * signed (APP_KEY-backed) token — no new session/login table, matching
 * decision 2's "lighter than a full account" spirit and this
 * codebase's existing reliance on Laravel's own encryption elsewhere
 * (Supplier.api_config, AdminUser.mfa_secret) rather than a new secret.
 * Unit, not Feature: no DB row is ever read or written by this service.
 */
class MembershipSessionTokenServiceTest extends TestCase
{
    public function test_issue_then_resolve_round_trips_the_email(): void
    {
        $service = new MembershipSessionTokenService();

        $token = $service->issue('member@example.com');

        $this->assertSame('member@example.com', $service->resolve($token));
    }

    public function test_resolve_rejects_a_garbage_token(): void
    {
        $service = new MembershipSessionTokenService();

        $this->assertNull($service->resolve('not-a-real-token'));
    }

    public function test_resolve_rejects_an_expired_token(): void
    {
        $service = new MembershipSessionTokenService();
        $this->travelTo(now()->subDays(31));
        $token = $service->issue('member@example.com');
        $this->travelBack();

        $this->assertNull($service->resolve($token));
    }

    public function test_resolve_accepts_a_token_still_inside_the_30_day_window(): void
    {
        $service = new MembershipSessionTokenService();
        $this->travelTo(now()->subDays(29));
        $token = $service->issue('member@example.com');
        $this->travelBack();

        $this->assertSame('member@example.com', $service->resolve($token));
    }
}
