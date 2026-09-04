<?php

namespace Tests\Feature\Http\Controllers\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-059 59c: the affiliate edits only its own contact + payout bank
 * details — `business_name` / `email` stay admin-controlled.
 */
class AffiliateProfileTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(Affiliate $affiliate): string
    {
        $user = AffiliateUser::query()->create([
            'affiliate_id' => $affiliate->id,
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
        $affiliate->update(['bank_name' => 'Maybank', 'bank_account_no' => '1234', 'bank_account_holder' => 'Acme Sdn Bhd']);

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
            'bank_name' => 'RHB',
        ])->assertOk();

        $this->assertDatabaseHas('affiliates', [
            'id' => $affiliate->id,
            'business_name' => 'Acme Resell',
            'email' => 'owner@acme.test',
        ]);
    }
}
