<?php

namespace Tests\Feature\Http\Controllers;

use App\Services\Membership\MembershipSessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-027's 2026-08-29 addendum, decisions 23/26/27: guest-callable,
 * no auth:sanctum (same trust model as CheckoutController/CatalogController,
 * ADR-011) — this is a phone-free, WhatsApp-free identity flow, email
 * -keyed and OTP-verified only.
 */
class MembershipOtpControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // ADR-061: send/verify resolve the storefront brand via
        // Affiliate::primary(), which fails loud when it is absent.
        $this->primaryAffiliate();
        Http::fake(['next-api.useplunk.com/*' => Http::response(['success' => true], 200)]);
    }

    public function test_send_generates_and_emails_a_code(): void
    {
        $this->postJson('/api/membership/otp/send', ['email' => 'member@example.com'])->assertOk();

        $this->assertDatabaseHas('membership_otp_codes', ['email' => 'member@example.com']);
        Http::assertSent(fn ($request) => $request['to'] === 'member@example.com');
    }

    public function test_send_rejects_an_invalid_email(): void
    {
        $this->postJson('/api/membership/otp/send', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_send_is_rate_limited_per_email(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/membership/otp/send', ['email' => 'member@example.com'])->assertOk();
        }

        $this->postJson('/api/membership/otp/send', ['email' => 'member@example.com'])
            ->assertStatus(429);
    }

    public function test_a_different_email_is_not_blocked_by_another_emails_rate_limit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/membership/otp/send', ['email' => 'member@example.com'])->assertOk();
        }

        $this->postJson('/api/membership/otp/send', ['email' => 'other@example.com'])->assertOk();
    }

    public function test_verify_returns_a_session_token_for_the_correct_code(): void
    {
        $this->postJson('/api/membership/otp/send', ['email' => 'member@example.com'])->assertOk();

        // The plain code isn't persisted anywhere (hashed only) —
        // recover it the same way the real email would carry it, from
        // Plunk's own captured (faked) request body.
        $sentBody = Http::recorded()[0][0]['body'];
        preg_match('/\d{6}/', $sentBody, $matches);
        $plainCode = $matches[0];

        $response = $this->postJson('/api/membership/otp/verify', ['email' => 'member@example.com', 'code' => $plainCode])
            ->assertOk();

        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertSame(
            ['affiliate_id' => $this->primaryAffiliate()->id, 'email' => 'member@example.com'],
            app(MembershipSessionTokenService::class)->resolve($token),
        );
    }

    public function test_verify_rejects_the_wrong_code(): void
    {
        $this->postJson('/api/membership/otp/send', ['email' => 'member@example.com'])->assertOk();

        $this->postJson('/api/membership/otp/verify', ['email' => 'member@example.com', 'code' => '000000'])
            ->assertUnprocessable();
    }

    public function test_verify_rejects_a_malformed_code(): void
    {
        $this->postJson('/api/membership/otp/verify', ['email' => 'member@example.com', 'code' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }
}
