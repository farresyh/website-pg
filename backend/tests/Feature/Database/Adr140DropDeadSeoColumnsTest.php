<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADR-140 decision 5, the contract release: `seo_title_local`, `seo_description_local`
 * and `seo_keywords` are dropped from `games`, a release after nothing reads or writes
 * them (PR 1, #781). Production held one repeated template in the two `_local` columns
 * and nothing in `seo_keywords`, with no view or index on any of them.
 */
class Adr140DropDeadSeoColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_dead_seo_columns_are_gone_from_games(): void
    {
        foreach (['seo_title_local', 'seo_description_local', 'seo_keywords'] as $column) {
            $this->assertFalse(Schema::hasColumn('games', $column), $column);
        }
    }

    public function test_the_game_seo_columns_that_are_used_remain(): void
    {
        foreach (['seo_title', 'seo_description', 'seo_og_image', 'schema_brand', 'schema_category', 'no_index', 'content_summary', 'content_about', 'content_faq', 'content_published_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('games', $column), $column);
        }
    }
}
