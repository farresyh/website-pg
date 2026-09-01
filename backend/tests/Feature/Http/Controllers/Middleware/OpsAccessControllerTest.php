<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-048 addendum: the one place this backend ever bootstraps a `web`
 * session — see OpsAccessController's own doc comment for why. Covers
 * both halves: minting the signed link (Sanctum-gated, super_admin only)
 * and visiting it (signature + is_active/role re-checked at use-time, not
 * just at mint-time).
 */
class OpsAccessControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mint_requires_authentication(): void
    {
        $this->postJson('/api/middleware/ops/horizon/link')->assertUnauthorized();
    }

    public function test_regular_admin_cannot_mint(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->postJson('/api/middleware/ops/horizon/link')->assertForbidden();
    }

    public function test_unknown_target_is_rejected(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));

        $this->postJson('/api/middleware/ops/not-a-real-target/link')->assertNotFound();
    }

    public function test_super_admin_can_mint_a_signed_link_for_each_target(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        foreach (['horizon', 'pulse'] as $target) {
            $response = $this->postJson("/api/middleware/ops/{$target}/link");

            $response->assertOk();
            $url = $response->json('url');
            $this->assertStringContainsString('/ops/enter', $url);
            $this->assertStringContainsString("target={$target}", $url);
            $this->assertStringContainsString('signature=', $url);
        }
    }

    public function test_visiting_a_valid_link_bootstraps_the_web_session_and_redirects(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);
        $url = $this->postJson('/api/middleware/ops/horizon/link')->json('url');

        $this->flushSession();
        $response = $this->get($url);

        $response->assertRedirect();
        $this->assertStringContainsString('/horizon', $response->headers->get('Location'));
        $this->assertAuthenticatedAs($admin, 'web');
    }

    public function test_a_tampered_link_is_rejected(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);
        $url = $this->postJson('/api/middleware/ops/horizon/link')->json('url');

        $tampered = str_replace('target=horizon', 'target=pulse', $url);

        $this->get($tampered)->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);
        $url = $this->postJson('/api/middleware/ops/horizon/link')->json('url');

        $this->travel(6)->minutes();

        $this->get($url)->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_a_link_for_an_admin_deactivated_after_minting_is_rejected(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);
        $url = $this->postJson('/api/middleware/ops/horizon/link')->json('url');

        $admin->update(['is_active' => false]);

        $this->get($url)->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_a_link_for_an_admin_demoted_after_minting_is_rejected(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);
        $url = $this->postJson('/api/middleware/ops/horizon/link')->json('url');

        $admin->update(['role' => 'admin']);

        $this->get($url)->assertForbidden();
        $this->assertGuest('web');
    }
}
