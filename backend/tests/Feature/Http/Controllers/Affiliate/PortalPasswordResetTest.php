<?php

namespace Tests\Feature\Http\Controllers\Affiliate;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateUser;
use App\Models\Reseller;
use App\Services\Auth\AccountOwnerType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * ADR-058 2026-11-12 addendum: a portal user (Reseller or Affiliate) who
 * forgot a password gets a reset link from the portal or from an admin.
 * One generic answer, one throttled sender, old sessions end on reset.
 */
class PortalPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = 'If an active account uses this email, a reset link is on its way. It expires in 24 hours.';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.plunk.api_key' => 'test-key', 'services.reseller_portal.url' => 'https://partner.example.test']);
        Http::fake();
    }

    private function resellerUser(array $overrides = []): AffiliateUser
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);

        return AffiliateUser::query()->create(array_merge([
            'owner_type' => AccountOwnerType::Reseller, 'owner_id' => $reseller->id,
            'name' => 'Ali', 'email' => 'ali@acme.test', 'password' => Hash::make('old-password'), 'is_active' => true,
        ], $overrides));
    }

    private function affiliateUser(): AffiliateUser
    {
        $affiliate = Affiliate::query()->create(['business_name' => 'OhahaStore', 'status' => 'active', 'markup_pct' => 0]);

        return AffiliateUser::query()->create([
            'owner_type' => AccountOwnerType::Affiliate, 'owner_id' => $affiliate->id,
            'name' => 'Siti', 'email' => 'siti@ohaha.test', 'password' => Hash::make('old-password'), 'is_active' => true,
        ]);
    }

    /** @return list<array<string, mixed>> the Plunk sends, decoded */
    private function sentMails(): array
    {
        return Http::recorded()->map(fn (array $pair) => json_decode($pair[0]->body(), true))->all();
    }

    private function resetLinkFrom(array $mail): string
    {
        preg_match('#https://partner\.example\.test/set-password\?[^"\s<]+#', html_entity_decode((string) ($mail['body'] ?? '')), $m);

        return $m[0] ?? '';
    }

    public function test_a_reseller_user_gets_a_reset_link_that_sets_a_new_password_and_ends_old_sessions(): void
    {
        $user = $this->resellerUser();
        $oldToken = $user->createToken('affiliate')->plainTextToken;

        $this->postJson('/api/affiliate/forgot-password', ['email' => 'ali@acme.test'])->assertOk()->assertJsonPath('message', self::GENERIC);

        $mails = $this->sentMails();
        $this->assertCount(1, $mails);
        $this->assertSame('Reset your portal password', $mails[0]['subject']);
        parse_str((string) parse_url($this->resetLinkFrom($mails[0]), PHP_URL_QUERY), $query);
        $this->assertSame('ali@acme.test', $query['email']);

        $this->postJson('/api/affiliate/set-password', ['email' => 'ali@acme.test', 'token' => $query['token'], 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertOk();

        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count(), 'the session opened with the old password ended');
        $this->withToken($oldToken)->getJson('/api/affiliate/me')->assertUnauthorized();
        $this->postJson('/api/affiliate/login', ['email' => 'ali@acme.test', 'password' => 'new-password-1'])->assertOk();
    }

    public function test_an_affiliate_user_uses_the_same_flow(): void
    {
        $this->affiliateUser();

        $this->postJson('/api/affiliate/forgot-password', ['email' => 'siti@ohaha.test'])->assertOk()->assertJsonPath('message', self::GENERIC);

        $this->assertCount(1, $this->sentMails());
    }

    public function test_an_unknown_or_inactive_email_gets_the_same_answer_and_no_mail(): void
    {
        $this->resellerUser(['email' => 'off@acme.test', 'is_active' => false]);

        $this->postJson('/api/affiliate/forgot-password', ['email' => 'nobody@acme.test'])->assertOk()->assertJsonPath('message', self::GENERIC);
        $this->postJson('/api/affiliate/forgot-password', ['email' => 'off@acme.test'])->assertOk()->assertJsonPath('message', self::GENERIC);

        $this->assertSame([], $this->sentMails());
        $this->assertDatabaseCount('affiliate_password_reset_tokens', 0);
    }

    public function test_a_second_request_within_a_minute_sends_nothing_but_answers_the_same(): void
    {
        $this->resellerUser();

        $this->postJson('/api/affiliate/forgot-password', ['email' => 'ali@acme.test'])->assertOk();
        $this->postJson('/api/affiliate/forgot-password', ['email' => 'ali@acme.test'])->assertOk()->assertJsonPath('message', self::GENERIC);

        $this->assertCount(1, $this->sentMails());
    }

    public function test_the_route_is_rate_limited_per_ip(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/affiliate/forgot-password', ['email' => "x{$i}@acme.test"])->assertOk();
        }

        $this->postJson('/api/affiliate/forgot-password', ['email' => 'x6@acme.test'])->assertTooManyRequests();
    }

    public function test_the_admin_button_refuses_an_inactive_login_and_a_repeat_within_a_minute(): void
    {
        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());
        $user = $this->resellerUser();

        $this->postJson("/api/resellers/{$user->owner_id}/users/{$user->id}/resend-invite")->assertOk();
        $this->postJson("/api/resellers/{$user->owner_id}/users/{$user->id}/resend-invite")->assertUnprocessable()->assertJsonValidationErrors('user');

        $user->update(['is_active' => false]);
        $this->travel(2)->minutes();
        $this->postJson("/api/resellers/{$user->owner_id}/users/{$user->id}/resend-invite")->assertUnprocessable()->assertJsonValidationErrors('user');

        $this->assertCount(1, $this->sentMails());
    }

    public function test_a_used_link_cannot_be_used_twice(): void
    {
        $user = $this->resellerUser();
        $token = Password::broker('affiliate_users')->createToken($user);
        $payload = ['email' => 'ali@acme.test', 'token' => $token, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'];

        $this->postJson('/api/affiliate/set-password', $payload)->assertOk();
        $this->postJson('/api/affiliate/set-password', ['password' => 'new-password-2', 'password_confirmation' => 'new-password-2'] + $payload)->assertUnprocessable();
    }
}
