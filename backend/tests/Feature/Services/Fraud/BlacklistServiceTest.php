<?php

namespace Tests\Feature\Services\Fraud;

use App\Models\BlacklistEntry;
use App\Services\Fraud\BlacklistEntryType;
use App\Services\Fraud\BlacklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlacklistServiceTest extends TestCase
{
    use RefreshDatabase;

    private function entry(array $overrides = []): BlacklistEntry
    {
        return BlacklistEntry::query()->create(array_merge([
            'type' => BlacklistEntryType::PlayerId->value,
            'value' => '123456',
            'reason' => 'Prior chargeback',
            'is_active' => true,
        ], $overrides));
    }

    public function test_matches_a_blacklisted_player_id(): void
    {
        $entry = $this->entry();

        $result = (new BlacklistService())->check('123456', 'clean@example.com', null);

        $this->assertNotNull($result);
        $this->assertSame($entry->id, $result->id);
    }

    public function test_matches_a_blacklisted_email(): void
    {
        $entry = $this->entry(['type' => BlacklistEntryType::Email->value, 'value' => 'fraud@example.com']);

        $result = (new BlacklistService())->check('999999', 'fraud@example.com', null);

        $this->assertSame($entry->id, $result->id);
    }

    public function test_matches_a_blacklisted_phone(): void
    {
        $entry = $this->entry(['type' => BlacklistEntryType::Phone->value, 'value' => '60123456789']);

        $result = (new BlacklistService())->check('999999', 'clean@example.com', '60123456789');

        $this->assertSame($entry->id, $result->id);
    }

    public function test_matches_a_blacklisted_email_case_insensitively(): void
    {
        $entry = $this->entry(['type' => BlacklistEntryType::Email->value, 'value' => 'Fraud@Example.com']);

        $result = (new BlacklistService())->check('999999', 'fraud@example.com', null);

        $this->assertNotNull($result);
        $this->assertSame($entry->id, $result->id);
    }

    public function test_returns_null_when_nothing_matches(): void
    {
        $this->entry();

        $result = (new BlacklistService())->check('999999', 'clean@example.com', null);

        $this->assertNull($result);
    }

    public function test_ignores_an_inactive_entry(): void
    {
        $this->entry(['is_active' => false]);

        $result = (new BlacklistService())->check('123456', 'clean@example.com', null);

        $this->assertNull($result);
    }

    public function test_record_hit_writes_an_audit_row(): void
    {
        $entry = $this->entry();

        $hit = (new BlacklistService())->recordHit($entry, '123456', 'clean@example.com', null, '203.0.113.5');

        $this->assertSame($entry->id, $hit->blacklist_entry_id);
        $this->assertSame('203.0.113.5', $hit->ip);
        $this->assertDatabaseHas('blacklist_hits', ['blacklist_entry_id' => $entry->id, 'ip' => '203.0.113.5']);
    }
}
