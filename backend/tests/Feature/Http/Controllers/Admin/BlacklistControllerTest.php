<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\BlacklistEntry;
use App\Services\Fraud\BlacklistEntryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BlacklistControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/blacklist')->assertUnauthorized();
    }

    public function test_admin_can_create_a_blacklist_entry(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->postJson('/api/blacklist', [
            'type' => 'player_id',
            'value' => '123456',
            'reason' => 'Prior chargeback on this account',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('type', 'player_id');
        $response->assertJsonPath('value', '123456');
        $response->assertJsonPath('is_active', true);
        $response->assertJsonPath('creator.id', $admin->id);
    }

    public function test_reason_is_required(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/blacklist', [
            'type' => 'email',
            'value' => 'fraud@example.com',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('reason');
    }

    public function test_type_must_be_a_known_value(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/blacklist', [
            'type' => 'ip_address',
            'value' => '1.2.3.4',
            'reason' => 'test',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('type');
    }

    public function test_rejects_a_duplicate_active_entry(): void
    {
        $this->actingAsAdmin();
        BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value,
            'value' => '123456',
            'reason' => 'already blocked',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/blacklist', [
            'type' => 'player_id',
            'value' => '123456',
            'reason' => 'duplicate attempt',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('value');
    }

    public function test_index_returns_stats_and_entries(): void
    {
        $this->actingAsAdmin();
        BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value, 'value' => '1', 'reason' => 'r', 'is_active' => true,
        ]);
        BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::Email->value, 'value' => 'a@b.com', 'reason' => 'r', 'is_active' => false,
        ]);

        $response = $this->getJson('/api/blacklist');

        $response->assertOk();
        $response->assertJsonPath('stats.active', 1);
        $response->assertJsonPath('stats.total', 2);
        $this->assertCount(2, $response->json('entries'));
    }

    public function test_show_returns_the_entry_with_its_hit_history(): void
    {
        $this->actingAsAdmin();
        $entry = BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value, 'value' => '123456', 'reason' => 'r', 'is_active' => true,
        ]);
        $entry->hits()->create(['player_id' => '123456', 'customer_email' => 'x@y.com', 'ip' => '203.0.113.9']);

        $response = $this->getJson("/api/blacklist/{$entry->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('hits'));
        $response->assertJsonPath('hits.0.ip', '203.0.113.9');
    }

    public function test_admin_can_deactivate_an_entry(): void
    {
        $this->actingAsAdmin();
        $entry = BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value, 'value' => '123456', 'reason' => 'r', 'is_active' => true,
        ]);

        $response = $this->patchJson("/api/blacklist/{$entry->id}/deactivate");

        $response->assertOk();
        $response->assertJsonPath('is_active', false);
        $this->assertFalse($entry->fresh()->is_active);
    }

    public function test_deactivating_an_already_inactive_entry_fails(): void
    {
        $this->actingAsAdmin();
        $entry = BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value, 'value' => '123456', 'reason' => 'r', 'is_active' => false,
        ]);

        $response = $this->patchJson("/api/blacklist/{$entry->id}/deactivate");

        $response->assertUnprocessable();
    }
}
