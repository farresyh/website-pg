<?php

namespace Tests\Unit\Services\Membership;

use App\Services\Membership\MembershipSessionTokenService;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * ADR-027's 2026-08-29 addendum, decisions 16/17/23: a stateless,
 * signed (APP_KEY-backed) token — no new session/login table, matching
 * decision 2's "lighter than a full account" spirit and this
 * codebase's existing reliance on Laravel's own encryption elsewhere
 * (Supplier.api_config, AdminUser.mfa_secret) rather than a new secret.
 * Unit, not Feature: no DB row is ever read or written by this service.
 *
 * ADR-061 decision 5 (PR-B): the token now carries the brand it was
 * minted on; `resolve()` returns `['affiliate_id' => int, 'email' => string]`.
 */
class MembershipSessionTokenServiceTest extends TestCase
{
    public function test_issue_then_resolve_round_trips_the_brand_and_email(): void
    {
        $service = new MembershipSessionTokenService;

        $token = $service->issue(7, 'member@example.com');

        $this->assertSame(['affiliate_id' => 7, 'email' => 'member@example.com'], $service->resolve($token));
    }

    public function test_resolve_rejects_a_garbage_token(): void
    {
        $service = new MembershipSessionTokenService;

        $this->assertNull($service->resolve('not-a-real-token'));
    }

    public function test_resolve_rejects_an_expired_token(): void
    {
        $service = new MembershipSessionTokenService;
        $this->travelTo(now()->subDays(31));
        $token = $service->issue(7, 'member@example.com');
        $this->travelBack();

        $this->assertNull($service->resolve($token));
    }

    public function test_resolve_accepts_a_token_still_inside_the_30_day_window(): void
    {
        $service = new MembershipSessionTokenService;
        $this->travelTo(now()->subDays(29));
        $token = $service->issue(7, 'member@example.com');
        $this->travelBack();

        $this->assertSame(['affiliate_id' => 7, 'email' => 'member@example.com'], $service->resolve($token));
    }

    /** A legacy token with no `affiliate_id` claim is rejected outright. */
    public function test_resolve_rejects_a_token_without_a_brand_claim(): void
    {
        $service = new MembershipSessionTokenService;
        $legacy = Crypt::encryptString(json_encode([
            'email' => 'member@example.com',
            'expires_at' => now()->addDay()->timestamp,
        ]));

        $this->assertNull($service->resolve($legacy));
    }
}
