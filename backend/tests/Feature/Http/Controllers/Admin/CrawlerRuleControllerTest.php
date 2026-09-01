<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\CrawlerRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ADR-029 addendum 2 decision 14: admin CRUD over robots.txt bot rules. */
class CrawlerRuleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/seo/crawler-rules')->assertForbidden();
    }

    public function test_index_orders_by_sort_order(): void
    {
        $this->actingAsSuperAdmin();
        CrawlerRule::query()->create(['bot_name' => 'Second', 'user_agent' => 'Second', 'is_allowed' => true, 'sort_order' => 2]);
        CrawlerRule::query()->create(['bot_name' => 'First', 'user_agent' => 'First', 'is_allowed' => true, 'sort_order' => 1]);

        $response = $this->getJson('/api/seo/crawler-rules');

        $response->assertOk();
        $this->assertSame('First', $response->json('0.bot_name'));
    }

    public function test_store_creates_a_rule(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/seo/crawler-rules', [
            'bot_name' => 'GPTBot',
            'user_agent' => 'GPTBot',
            'is_allowed' => false,
            'disallow_paths' => ['/'],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('crawler_rules', ['bot_name' => 'GPTBot', 'is_allowed' => 0]);
    }

    public function test_store_rejects_a_disallow_path_not_starting_with_a_slash(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/seo/crawler-rules', [
            'bot_name' => 'GPTBot',
            'user_agent' => 'GPTBot',
            'is_allowed' => false,
            'disallow_paths' => ['no-leading-slash'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('disallow_paths.0');
    }

    public function test_store_rejects_a_duplicate_user_agent(): void
    {
        $this->actingAsSuperAdmin();
        CrawlerRule::query()->create(['bot_name' => 'Googlebot', 'user_agent' => 'Googlebot', 'is_allowed' => true]);

        $this->postJson('/api/seo/crawler-rules', ['bot_name' => 'Googlebot Again', 'user_agent' => 'Googlebot', 'is_allowed' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_agent');
    }

    public function test_store_invalidates_the_robots_cache(): void
    {
        $this->actingAsSuperAdmin();
        Cache::put('catalog.public.crawler_rules', ['stale' => true], 60);

        $this->postJson('/api/seo/crawler-rules', ['bot_name' => 'GPTBot', 'user_agent' => 'GPTBot', 'is_allowed' => false])->assertCreated();

        $this->assertFalse(Cache::has('catalog.public.crawler_rules'));
    }

    public function test_update_allows_keeping_the_same_user_agent(): void
    {
        $this->actingAsSuperAdmin();
        $rule = CrawlerRule::query()->create(['bot_name' => 'Googlebot', 'user_agent' => 'Googlebot', 'is_allowed' => true]);

        $response = $this->putJson("/api/seo/crawler-rules/{$rule->id}", [
            'bot_name' => 'Googlebot',
            'user_agent' => 'Googlebot',
            'is_allowed' => false,
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('is_allowed'));
    }

    public function test_destroy_removes_the_rule_and_invalidates_the_robots_cache(): void
    {
        $this->actingAsSuperAdmin();
        $rule = CrawlerRule::query()->create(['bot_name' => 'Googlebot', 'user_agent' => 'Googlebot', 'is_allowed' => true]);
        Cache::put('catalog.public.crawler_rules', ['stale' => true], 60);

        $this->deleteJson("/api/seo/crawler-rules/{$rule->id}")->assertNoContent();

        $this->assertDatabaseMissing('crawler_rules', ['id' => $rule->id]);
        $this->assertFalse(Cache::has('catalog.public.crawler_rules'));
    }
}
