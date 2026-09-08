<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\PlatformSettings;
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
        // ADR-080 decision 2: both endpoints are now gated on
        // `membershipEnabledEffective()` — the global switch has to be on
        // for the happy-path cases (the primary affiliate's own toggle
        // defaults on via the TestCase helper).
        $this->primaryAffiliate();
        PlatformSettings::current()->update(['membership_enabled' => true]);
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

    // --- ADR-080 decision 2: the OTP surface is hard-gated on a
    //     membership-disabled brand (previously ungated). ---

    public function test_send_is_403_when_the_brand_membership_toggle_is_off(): void
    {
        $this->primaryAffiliate()->update(['membership_enabled' => false]);

        $this->postJson('/api/membership/otp/send', ['email' => 'member@example.com'])
            ->assertForbidden()
            ->assertJson(['message' => 'Membership is not available.']);

        $this->assertDatabaseMissing('membership_otp_codes', ['email' => 'member@example.com']);
        Http::assertNothingSent();
    }

    public function test_send_is_403_when_the_global_membership_switch_is_off(): void
    {
        PlatformSettings::current()->update(['membership_enabled' => false]);

        $this->postJson('/api/membership/otp/send', ['email' => 'member@example.com'])
            ->assertForbidden();
    }

    public function test_verify_is_403_when_membership_is_disabled(): void
    {
        $this->primaryAffiliate()->update(['membership_enabled' => false]);

        $this->postJson('/api/membership/otp/verify', ['email' => 'member@example.com', 'code' => '123456'])
            ->assertForbidden();
    }

    public function test_the_membership_gate_runs_before_the_rate_limiter(): void
    {
        $this->primaryAffiliate()->update(['membership_enabled' => false]);

        // The `otp-request` limiter is 3/hour. A disabled brand must
        // always 403 and never consume a bucket, so the 4th call is
        // still 403, not 429.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/membership/otp/send', ['email' => 'member@example.com'])->assertForbidden();
        }
    }
}
