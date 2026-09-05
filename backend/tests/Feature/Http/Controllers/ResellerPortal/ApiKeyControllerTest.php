<?php

namespace Tests\Feature\Http\Controllers\ResellerPortal;

use App\Models\AffiliateUser;
use App\Models\Reseller;
use App\Models\ResellerApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-072 decision 5 / PR-G planning addendum decision 7: full
 * self-service Reseller API key management, capped at 5 active keys.
 */
class ApiKeyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(): Reseller
    {
        return Reseller::query()->create(['business_name' => 'Wallet Reseller', 'is_active' => true]);
    }

    private function tokenFor(Reseller $reseller): string
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => 'reseller', 'owner_id' => $reseller->id,
            'name' => 'Staff', 'email' => 'staff+'.$reseller->id.'@wallet-reseller.test',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    public function test_store_issues_a_key_and_returns_the_plaintext_once(): void
    {
        $reseller = $this->reseller();

        $response = $this->withToken($this->tokenFor($reseller))->postJson('/api/reseller-portal/api-keys', ['name' => 'My integration']);

        $response->assertCreated();
        $this->assertStringStartsWith('pgrk_', $response->json('plain_text_key'));
        $this->assertSame(1, ResellerApiKey::query()->where('reseller_id', $reseller->id)->count());
    }

    public function test_index_lists_only_this_resellers_own_keys(): void
    {
        $mine = $this->reseller();
        $other = Reseller::query()->create(['business_name' => 'Other', 'is_active' => true]);
        ResellerApiKey::query()->create(['reseller_id' => $other->id, 'name' => 'Not mine', 'key_hash' => 'x']);
        $token = $this->tokenFor($mine);

        $this->withToken($token)->postJson('/api/reseller-portal/api-keys', ['name' => 'Mine']);

        $response = $this->withToken($token)->getJson('/api/reseller-portal/api-keys');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('Mine', $response->json('0.name'));
    }

    public function test_store_rejects_a_sixth_active_key(): void
    {
        $reseller = $this->reseller();
        $token = $this->tokenFor($reseller);

        for ($i = 1; $i <= 5; $i++) {
            $this->withToken($token)->postJson('/api/reseller-portal/api-keys', ['name' => "Key {$i}"])->assertCreated();
        }

        $this->withToken($token)->postJson('/api/reseller-portal/api-keys', ['name' => 'Key 6'])->assertUnprocessable();
        $this->assertSame(5, ResellerApiKey::query()->where('reseller_id', $reseller->id)->count());
    }

    public function test_a_revoked_key_does_not_count_toward_the_cap(): void
    {
        $reseller = $this->reseller();
        $token = $this->tokenFor($reseller);

        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $this->withToken($token)->postJson('/api/reseller-portal/api-keys', ['name' => "Key {$i}"])->json('id');
        }

        $this->withToken($token)->deleteJson("/api/reseller-portal/api-keys/{$ids[0]}")->assertNoContent();

        $this->withToken($token)->postJson('/api/reseller-portal/api-keys', ['name' => 'Key 6'])->assertCreated();
    }

    public function test_destroy_revokes_a_key_owned_by_this_reseller(): void
    {
        $reseller = $this->reseller();
        $token = $this->tokenFor($reseller);
        $keyId = $this->withToken($token)->postJson('/api/reseller-portal/api-keys', ['name' => 'x'])->json('id');

        $this->withToken($token)->deleteJson("/api/reseller-portal/api-keys/{$keyId}")->assertNoContent();
        $this->assertNotNull(ResellerApiKey::query()->find($keyId)->revoked_at);
    }

    public function test_destroy_404s_for_another_resellers_key(): void
    {
        $mine = $this->reseller();
        $other = Reseller::query()->create(['business_name' => 'Other', 'is_active' => true]);
        $otherKey = ResellerApiKey::query()->create(['reseller_id' => $other->id, 'name' => 'Not mine', 'key_hash' => 'x']);

        $this->withToken($this->tokenFor($mine))->deleteJson("/api/reseller-portal/api-keys/{$otherKey->id}")->assertNotFound();
        $this->assertNull($otherKey->fresh()->revoked_at);
    }
}
