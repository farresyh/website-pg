<?php

namespace Tests\Feature\Http\Controllers\ResellerPortal;

use App\Models\AffiliateUser;
use App\Models\Reseller;
use App\Models\ResellerTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** ADR-072 decision 5 / PR-G: the Reseller (wallet) portal's view-only Profile screen. */
class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_the_resellers_own_business_details_and_tier(): void
    {
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 1]);
        $reseller = Reseller::query()->create([
            'business_name' => 'Wallet Reseller', 'contact_name' => 'Ah Kow', 'email' => 'reseller@example.com',
            'phone' => '0123456789', 'reseller_tier_id' => $tier->id, 'is_active' => true,
        ]);
        $user = AffiliateUser::query()->create([
            'owner_type' => 'reseller', 'owner_id' => $reseller->id,
            'name' => 'Staff', 'email' => 'staff@wallet-reseller.test',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);
        $token = $user->createToken('affiliate')->plainTextToken;

        $this->withToken($token)->getJson('/api/reseller-portal/profile')
            ->assertOk()
            ->assertJsonPath('business_name', 'Wallet Reseller')
            ->assertJsonPath('contact_name', 'Ah Kow')
            ->assertJsonPath('tier_name', 'Gold')
            // The tier's markup_percent is the platform's margin over
            // cost — private, never exposed to the reseller.
            ->assertJsonMissingPath('markup_percent');
    }
}
