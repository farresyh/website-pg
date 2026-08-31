<?php

namespace Tests\Feature\Http\Controllers\Reseller;

use App\Models\Reseller;
use App\Models\ResellerUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-059 59c: the reseller edits only its own contact + payout bank
 * details — `business_name` / `email` stay admin-controlled.
 */
class ResellerProfileTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(Reseller $reseller): string
    {
        $user = ResellerUser::query()->create([
            'reseller_id' => $reseller->id,
            'name' => 'Staff',
            'email' => 'staff@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return $user->createToken('reseller')->plainTextToken;
    }

    private function reseller(): Reseller
    {
        return Reseller::query()->create([
            'business_name' => 'Acme Resell',
            'email' => 'owner@acme.test',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
    }

    public function test_show_returns_the_reseller_profile(): void
    {
        $reseller = $this->reseller();
        $reseller->update(['bank_name' => 'Maybank', 'bank_account_no' => '1234', 'bank_account_holder' => 'Acme Sdn Bhd']);

        $this->withToken($this->tokenFor($reseller))->getJson('/api/reseller/profile')
            ->assertOk()
            ->assertJsonPath('business_name', 'Acme Resell')
            ->assertJsonPath('email', 'owner@acme.test')
            ->assertJsonPath('bank_name', 'Maybank');
    }

    public function test_update_saves_bank_details_and_contact_fields(): void
    {
        $reseller = $this->reseller();

        $this->withToken($this->tokenFor($reseller))->putJson('/api/reseller/profile', [
            'contact_name' => 'Ali',
            'phone' => '+60123456789',
            'bank_name' => 'CIMB',
            'bank_account_no' => '99887766',
            'bank_account_holder' => 'Acme Sdn Bhd',
        ])->assertOk()->assertJsonPath('bank_name', 'CIMB');

        $this->assertDatabaseHas('resellers', [
            'id' => $reseller->id,
            'contact_name' => 'Ali',
            'bank_account_no' => '99887766',
        ]);
    }

    public function test_update_cannot_change_business_name_or_email(): void
    {
        $reseller = $this->reseller();

        $this->withToken($this->tokenFor($reseller))->putJson('/api/reseller/profile', [
            'business_name' => 'Hacked Name',
            'email' => 'attacker@evil.test',
            'bank_name' => 'RHB',
        ])->assertOk();

        $this->assertDatabaseHas('resellers', [
            'id' => $reseller->id,
            'business_name' => 'Acme Resell',
            'email' => 'owner@acme.test',
        ]);
    }
}
