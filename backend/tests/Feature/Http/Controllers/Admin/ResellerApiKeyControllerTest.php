<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ADR-074 decision 1: admin issues/revokes a Reseller's API keys. */
class ResellerApiKeyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function makeReseller(): Reseller
    {
        return Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $reseller = $this->makeReseller();

        $this->getJson("/api/resellers/{$reseller->id}/api-keys")->assertForbidden();
    }

    public function test_store_issues_a_key_and_returns_the_plaintext_once(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();

        $response = $this->postJson("/api/resellers/{$reseller->id}/api-keys", ['name' => 'Production key']);

        $response->assertCreated();
        $response->assertJsonPath('name', 'Production key');
        $this->assertStringStartsWith('pgrk_', $response->json('plain_text_key'));
        $this->assertDatabaseHas('reseller_api_keys', ['reseller_id' => $reseller->id, 'name' => 'Production key']);
    }

    public function test_index_never_leaks_the_key_hash_or_plaintext(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();
        app(ResellerApiKeyService::class)->issue($reseller, 'Production key');

        $response = $this->getJson("/api/resellers/{$reseller->id}/api-keys");

        $response->assertOk();
        $response->assertJsonMissingPath('0.key_hash');
        $response->assertJsonMissingPath('0.plain_text_key');
        $response->assertJsonPath('0.name', 'Production key');
    }

    public function test_destroy_revokes_the_key(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();
        $issued = app(ResellerApiKeyService::class)->issue($reseller, 'Production key');

        $response = $this->deleteJson("/api/resellers/{$reseller->id}/api-keys/{$issued['key']->id}");

        $response->assertNoContent();
        $this->assertNotNull($issued['key']->refresh()->revoked_at);
    }

    public function test_destroy_404s_for_a_key_belonging_to_a_different_reseller(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();
        $otherReseller = Reseller::query()->create(['business_name' => 'Other', 'is_active' => true]);
        $issued = app(ResellerApiKeyService::class)->issue($otherReseller, 'Someone else\'s key');

        $response = $this->deleteJson("/api/resellers/{$reseller->id}/api-keys/{$issued['key']->id}");

        $response->assertNotFound();
        $this->assertNull($issued['key']->refresh()->revoked_at);
    }

    public function test_store_requires_authentication(): void
    {
        $reseller = $this->makeReseller();

        $this->postJson("/api/resellers/{$reseller->id}/api-keys", ['name' => 'x'])->assertUnauthorized();
    }
}
