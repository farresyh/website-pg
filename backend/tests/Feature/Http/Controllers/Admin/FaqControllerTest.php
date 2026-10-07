<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateDomain;
use App\Models\Faq;
use App\Services\Affiliate\AffiliateDomainStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-120 decision 13: FAQ is admin-central content (one platform set),
 * served per brand with `{store_name}` substituted — the same model as
 * footer/legal text (ADR-060 addendum).
 */
class FaqControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The migration seeds the storefront's former hardcoded FAQ; start clean.
        Faq::query()->delete();
        $this->primaryAffiliate();
    }

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/seo/faqs')->assertForbidden();
    }

    public function test_crud_round_trip(): void
    {
        $this->actingAsSuperAdmin();

        $id = $this->postJson('/api/seo/faqs', [
            'question' => 'How fast?',
            'answer' => 'Within minutes at {store_name}.',
            'sort_order' => 2,
            'is_active' => true,
        ])->assertCreated()->json('id');

        $this->putJson("/api/seo/faqs/{$id}", [
            'question' => 'How fast is delivery?',
            'answer' => 'Usually 1–3 minutes.',
            'sort_order' => 1,
            'is_active' => false,
        ])->assertOk()->assertJsonPath('is_active', false);

        $this->getJson('/api/seo/faqs')->assertOk()->assertJsonPath('0.question', 'How fast is delivery?');

        $this->deleteJson("/api/seo/faqs/{$id}")->assertNoContent();
        $this->assertDatabaseCount('faqs', 0);
    }

    public function test_store_validates_input(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/seo/faqs', ['question' => '', 'answer' => str_repeat('x', 2001), 'sort_order' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['question', 'answer', 'sort_order', 'is_active']);
    }

    public function test_public_list_is_active_only_ordered_and_brand_substituted(): void
    {
        Faq::query()->create(['question' => 'Second', 'answer' => 'b', 'sort_order' => 2, 'is_active' => true]);
        Faq::query()->create(['question' => 'Hidden', 'answer' => 'h', 'sort_order' => 0, 'is_active' => false]);
        Faq::query()->create(['question' => 'Why {store_name}?', 'answer' => '{store_name} is fast.', 'sort_order' => 1, 'is_active' => true]);

        $affiliate = Affiliate::query()->create(['business_name' => 'Acme', 'markup_pct' => 10, 'status' => 'active']);
        AffiliateBranding::query()->create(['affiliate_id' => $affiliate->id, 'store_name' => 'Acme Store']);
        AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id,
            'hostname' => 'acme.com',
            'status' => AffiliateDomainStatus::Active,
            'is_primary' => true,
        ]);

        $this->getJson('/api/catalog/seo/faqs', ['X-Storefront-Host' => 'acme.com'])
            ->assertOk()
            ->assertExactJson([
                ['question' => 'Why Acme Store?', 'answer' => 'Acme Store is fast.'],
                ['question' => 'Second', 'answer' => 'b'],
            ]);
    }

    public function test_an_admin_write_busts_the_public_list(): void
    {
        $this->getJson('/api/catalog/seo/faqs')->assertExactJson([]);

        $this->actingAsSuperAdmin();
        $this->postJson('/api/seo/faqs', ['question' => 'Q', 'answer' => 'A', 'sort_order' => 0, 'is_active' => true])->assertCreated();

        $this->getJson('/api/catalog/seo/faqs')->assertJsonPath('0.question', 'Q');
    }
}
