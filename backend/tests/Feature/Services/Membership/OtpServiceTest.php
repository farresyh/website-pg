<?php

namespace Tests\Feature\Services\Membership;

use App\Models\MembershipOtpCode;
use App\Models\Reseller;
use App\Services\Membership\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-027's 2026-08-29 addendum, decisions 23/26/27: OTP is a
 * short-lived, hashed, attempt-limited code per email — never stored
 * or logged in plaintext, matching this codebase's own discipline for
 * anything credential-shaped (AdminUser.mfa_secret, Supplier.api_config).
 * A DB-backed Feature test (not Unit) since generate()/verify() are
 * inseparable from persistence here — there's no pure-calculation piece
 * to isolate the way MembershipPricingService has.
 *
 * ADR-061 decision 5 (PR-B): every code is scoped to the brand that
 * issued it — `verify()` takes the reseller id and never matches across
 * brands.
 */
class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $rid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rid = $this->primaryReseller()->id;
    }

    public function test_generate_creates_a_hashed_unexpired_code(): void
    {
        $service = new OtpService();

        $code = $service->generate($this->rid, 'member@example.com');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $row = MembershipOtpCode::query()->where('email', 'member@example.com')->firstOrFail();
        $this->assertSame($this->rid, $row->reseller_id);
        $this->assertNotSame($code, $row->code_hash);
        $this->assertTrue(Hash::check($code, $row->code_hash));
        $this->assertTrue($row->expires_at->isFuture());
        $this->assertNull($row->consumed_at);
        $this->assertSame(0, $row->attempts);
    }

    public function test_verify_succeeds_with_the_correct_code_and_consumes_it(): void
    {
        $service = new OtpService();
        $code = $service->generate($this->rid, 'member@example.com');

        $this->assertTrue($service->verify($this->rid, 'member@example.com', $code));

        $row = MembershipOtpCode::query()->where('email', 'member@example.com')->firstOrFail();
        $this->assertNotNull($row->consumed_at);
    }

    public function test_verify_fails_with_the_wrong_code(): void
    {
        $service = new OtpService();
        $service->generate($this->rid, 'member@example.com');

        $this->assertFalse($service->verify($this->rid, 'member@example.com', '000000'));
    }

    /** A consumed code can never be reused, even if the correct code is resubmitted. */
    public function test_verify_fails_once_the_code_is_already_consumed(): void
    {
        $service = new OtpService();
        $code = $service->generate($this->rid, 'member@example.com');
        $service->verify($this->rid, 'member@example.com', $code);

        $this->assertFalse($service->verify($this->rid, 'member@example.com', $code));
    }

    public function test_verify_fails_once_the_code_has_expired(): void
    {
        $service = new OtpService();
        $code = $service->generate($this->rid, 'member@example.com');
        MembershipOtpCode::query()->where('email', 'member@example.com')->update(['expires_at' => now()->subMinute()]);

        $this->assertFalse($service->verify($this->rid, 'member@example.com', $code));
    }

    /** Decision 26's anti-abuse pairing: brute-forcing a short numeric code must lock out after a few tries, not be bounded only by the OTP-request throttle. */
    public function test_verify_locks_out_after_max_attempts_even_with_the_correct_code(): void
    {
        $service = new OtpService();
        $code = $service->generate($this->rid, 'member@example.com');

        for ($i = 0; $i < 5; $i++) {
            $service->verify($this->rid, 'member@example.com', '000000');
        }

        $this->assertFalse($service->verify($this->rid, 'member@example.com', $code));
    }

    /** A fresh generate() for the same email must not let an old code (or its attempt count) interfere. */
    public function test_verify_only_matches_the_most_recently_generated_code(): void
    {
        $service = new OtpService();
        $service->generate($this->rid, 'member@example.com');
        $second = $service->generate($this->rid, 'member@example.com');

        $this->assertTrue($service->verify($this->rid, 'member@example.com', $second));
    }

    /** ADR-061 decision 5: a code issued on brand A never verifies on brand B. */
    public function test_verify_never_matches_a_code_issued_for_another_brand(): void
    {
        $service = new OtpService();
        $otherBrand = Reseller::query()->create([
            'business_name' => 'Other Brand',
            'markup_pct' => 0,
            'status' => 'active',
        ]);
        $code = $service->generate($this->rid, 'member@example.com');

        $this->assertFalse($service->verify($otherBrand->id, 'member@example.com', $code));
        $this->assertTrue($service->verify($this->rid, 'member@example.com', $code));
    }
}
