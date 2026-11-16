<?php

namespace Tests\Feature\Http\Controllers\Affiliate;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateBankChange;
use App\Models\AffiliateImpersonationSession;
use App\Models\AffiliateUser;
use App\Services\Affiliate\AffiliateStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-059 59c: the affiliate edits only its own contact + payout bank
 * details — `business_name` / `email` stay admin-controlled.
 */
class AffiliateProfileTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_BANK = ['bank_name' => 'CIMB', 'bank_account_no' => '99887766', 'bank_account_holder' => 'Acme Sdn Bhd'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*useplunk.com/*' => Http::response(['success' => true])]);
    }

    /** @return list<string> the recipients of every Plunk email sent */
    private function emailedTo(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'useplunk.com'))
            ->map(fn (Request $r) => $r['to'])->values()->all();
    }

    private function tokenFor(Affiliate $affiliate): string
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => 'affiliate',
            'owner_id' => $affiliate->id,
            'name' => 'Staff',
            'email' => 'staff@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    private function affiliate(): Affiliate
    {
        return Affiliate::query()->create([
            'business_name' => 'Acme Resell',
            'email' => 'owner@acme.test',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
    }

    public function test_show_returns_the_affiliate_profile(): void
    {
        $affiliate = $this->affiliate();
        $affiliate->forceFill(['bank_name' => 'Maybank', 'bank_account_no' => '1234', 'bank_account_holder' => 'Acme Sdn Bhd'])->save();

        $this->withToken($this->tokenFor($affiliate))->getJson('/api/affiliate/profile')
            ->assertOk()
            ->assertJsonPath('business_name', 'Acme Resell')
            ->assertJsonPath('email', 'owner@acme.test')
            ->assertJsonPath('bank_name', 'Maybank');
    }

    public function test_update_saves_bank_details_and_contact_fields(): void
    {
        $affiliate = $this->affiliate();

        $this->withToken($this->tokenFor($affiliate))->putJson('/api/affiliate/profile', [
            'contact_name' => 'Ali',
            'phone' => '+60123456789',
            'bank_name' => 'CIMB',
            'bank_account_no' => '99887766',
            'bank_account_holder' => 'Acme Sdn Bhd',
        ])->assertOk()->assertJsonPath('bank_name', 'CIMB');

        $this->assertDatabaseHas('affiliates', [
            'id' => $affiliate->id,
            'contact_name' => 'Ali',
            'bank_account_no' => '99887766',
        ]);
    }

    public function test_update_cannot_change_business_name_or_email(): void
    {
        $affiliate = $this->affiliate();

        $this->withToken($this->tokenFor($affiliate))->putJson('/api/affiliate/profile', [
            'business_name' => 'Hacked Name',
            'email' => 'attacker@evil.test',
            ...self::NEW_BANK,
        ])->assertOk();

        $this->assertDatabaseHas('affiliates', [
            'id' => $affiliate->id,
            'business_name' => 'Acme Resell',
            'email' => 'owner@acme.test',
        ]);
    }

    /**
     * Item 34: `filled` rejects an empty string outright — before this
     * fix, `nullable` let it through as a "valid" bank_name, which
     * Affiliate\WithdrawalController::store()'s `=== null` guard couldn't
     * catch (an empty string isn't null).
     */
    public function test_update_rejects_an_empty_string_bank_name(): void
    {
        $affiliate = $this->affiliate();

        $this->withToken($this->tokenFor($affiliate))->putJson('/api/affiliate/profile', [
            'bank_name' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('bank_name');

        $this->assertDatabaseMissing('affiliates', ['id' => $affiliate->id, 'bank_name' => '']);
    }

    public function test_update_can_still_omit_bank_fields_entirely(): void
    {
        $affiliate = $this->affiliate();
        $affiliate->forceFill(['bank_name' => 'Maybank', 'bank_account_no' => '1234', 'bank_account_holder' => 'Acme Sdn Bhd'])->save();

        $this->withToken($this->tokenFor($affiliate))->putJson('/api/affiliate/profile', [
            'phone' => '+60123456789',
        ])->assertOk();

        $this->assertDatabaseHas('affiliates', ['id' => $affiliate->id, 'bank_name' => 'Maybank']);
    }

    /** ADR-058 2026-11-16 addendum: the three bank fields are one account. */
    public function test_one_bank_field_alone_is_refused(): void
    {
        $affiliate = $this->affiliate();

        $this->withToken($this->tokenFor($affiliate))->putJson('/api/affiliate/profile', ['bank_name' => 'RHB'])
            ->assertUnprocessable()->assertJsonValidationErrors(['bank_account_no', 'bank_account_holder']);

        $this->assertSame(0, AffiliateBankChange::query()->count());
    }

    public function test_a_bank_change_is_recorded_and_the_affiliate_and_admins_are_emailed(): void
    {
        $admin = AdminUser::factory()->create(['email' => 'boss@pekangame.test']);
        $affiliate = $this->affiliate();
        $affiliate->forceFill(['bank_name' => 'Maybank', 'bank_account_no' => '1111222233', 'bank_account_holder' => 'Acme'])->save();
        $token = $this->tokenFor($affiliate);

        $this->withToken($token)->putJson('/api/affiliate/profile', self::NEW_BANK)->assertOk();

        $change = AffiliateBankChange::query()->sole();
        $this->assertSame('1111222233', $change->old_bank_account_no);
        $this->assertSame('99887766', $change->new_bank_account_no);
        $this->assertNotNull($change->affiliate_user_id);
        $this->assertNull($change->impersonation_session_id);

        $to = $this->emailedTo();
        $this->assertContains('staff@acme.test', $to);
        $this->assertContains('owner@acme.test', $to);
        $this->assertContains($admin->email, $to);
    }

    public function test_saving_the_same_bank_details_records_and_emails_nothing(): void
    {
        $affiliate = $this->affiliate();
        $affiliate->forceFill(self::NEW_BANK)->save();

        $this->withToken($this->tokenFor($affiliate))->putJson('/api/affiliate/profile', [...self::NEW_BANK, 'phone' => '+60111'])->assertOk();

        $this->assertSame(0, AffiliateBankChange::query()->count());
        $this->assertSame([], $this->emailedTo());
    }

    public function test_a_change_made_while_impersonating_names_the_session(): void
    {
        $affiliate = $this->affiliate();
        $this->tokenFor($affiliate);
        $user = AffiliateUser::query()->sole();
        $token = $user->createToken('impersonation', ['impersonate']);
        $session = AffiliateImpersonationSession::query()->create([
            'admin_user_id' => AdminUser::factory()->create()->id,
            'affiliate_id' => $affiliate->id,
            'personal_access_token_id' => $token->accessToken->id,
            'started_at' => now(),
        ]);

        $this->withToken($token->plainTextToken)->putJson('/api/affiliate/profile', self::NEW_BANK)->assertOk();

        $this->assertSame($session->id, AffiliateBankChange::query()->sole()->impersonation_session_id);
    }

    /** RES-5: a deactivated affiliate's portal is read-only, the bank details included. */
    public function test_a_deactivated_affiliate_cannot_change_anything(): void
    {
        $affiliate = $this->affiliate();
        $token = $this->tokenFor($affiliate);
        $affiliate->update(['status' => AffiliateStatus::Inactive]);

        $this->withToken($token)->getJson('/api/affiliate/profile')->assertOk()->assertJsonPath('writable', false);
        $this->withToken($token)->putJson('/api/affiliate/profile', [...self::NEW_BANK, 'phone' => '+60111'])->assertForbidden();

        $this->assertDatabaseHas('affiliates', ['id' => $affiliate->id, 'bank_name' => null, 'phone' => null]);
        $this->assertSame(0, AffiliateBankChange::query()->count());
    }
}
