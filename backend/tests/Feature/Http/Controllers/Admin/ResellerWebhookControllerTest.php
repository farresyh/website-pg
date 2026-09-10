<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Reseller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-084 PR-3 decision 10: admin support-side view/set/rotate/disable of
 * a Reseller's delivery webhook. super_admin only.
 */
class ResellerWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
    }

    private function reseller(): Reseller
    {
        return Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $reseller = $this->reseller();

        $this->getJson("/api/resellers/{$reseller->id}/webhook")->assertForbidden();
    }

    public function test_set_and_rotate_from_admin(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->reseller();

        $created = $this->postJson("/api/resellers/{$reseller->id}/webhook", ['url' => 'https://example.test/hook']);
        $created->assertCreated()->assertJsonPath('webhook.url', 'https://example.test/hook');
        $this->assertStringStartsWith('pgwh_', $created->json('secret'));

        $rotated = $this->postJson("/api/resellers/{$reseller->id}/webhook/rotate-secret");
        $rotated->assertOk();
        $this->assertNotSame($created->json('secret'), $rotated->json('secret'));
    }

    public function test_show_reflects_the_portal_configured_endpoint(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->reseller();
        $this->postJson("/api/resellers/{$reseller->id}/webhook", ['url' => 'https://example.test/hook']);

        $this->getJson("/api/resellers/{$reseller->id}/webhook")
            ->assertOk()
            ->assertJsonPath('webhook.url', 'https://example.test/hook')
            ->assertJsonMissingPath('webhook.secret');
    }

    public function test_status_and_destroy(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->reseller();
        $this->postJson("/api/resellers/{$reseller->id}/webhook", ['url' => 'https://example.test/hook']);

        $this->patchJson("/api/resellers/{$reseller->id}/webhook/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('webhook.is_active', false);

        $this->deleteJson("/api/resellers/{$reseller->id}/webhook")->assertNoContent();
        $this->assertNull($reseller->fresh()->webhook);
    }

    public function test_rejects_a_non_https_url(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->reseller();

        $this->postJson("/api/resellers/{$reseller->id}/webhook", ['url' => 'http://example.test/hook'])
            ->assertUnprocessable();
    }
}
