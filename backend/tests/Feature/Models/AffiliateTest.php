<?php

namespace Tests\Feature\Models;

use App\Models\Affiliate;
use App\Models\PlatformSettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-061: the `is_primary` / `is_owned` / `membership_enabled` flags
 * that replace the `business_name = 'Platform Owner'` magic string.
 */
class AffiliateTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_returns_the_single_is_primary_row(): void
    {
        Affiliate::query()->create(['business_name' => 'A third-party affiliate', 'markup_pct' => 5]);
        $primary = $this->primaryAffiliate();

        $this->assertSame($primary->id, Affiliate::primary()->id);
        $this->assertTrue(Affiliate::primary()->is_primary);
    }

    public function test_primary_throws_loud_when_there_is_no_primary(): void
    {
        Affiliate::query()->create(['business_name' => 'Only an affiliate', 'markup_pct' => 5]);

        // ModelNotFoundException (via Builder::sole()) — never a silent
        // firstOrCreate the way platformOwner() used to.
        $this->expectException(ModelNotFoundException::class);
        Affiliate::primary();
    }

    public function test_a_second_primary_row_is_rejected_by_the_database(): void
    {
        $this->primaryAffiliate();

        // The portable nullable-unique index: NULLs don't collide, a
        // second `1` does — "exactly one primary" is a DB guarantee, not
        // an application convention.
        $this->expectException(QueryException::class);
        Affiliate::query()->create([
            'business_name' => 'Second primary',
            'markup_pct' => 0,
            'is_primary' => true,
        ]);
    }

    public function test_a_third_party_affiliate_stores_null_not_false_for_is_primary(): void
    {
        $this->primaryAffiliate();
        $affiliate = Affiliate::query()->create(['business_name' => 'Affiliate B', 'markup_pct' => 5]);

        // NULL, not 0 — that is what keeps the nullable-unique index from
        // colliding. Every read path treats it as falsy or casts (bool).
        $this->assertNull($affiliate->fresh()->getRawOriginal('is_primary'));
        $this->assertFalse((bool) $affiliate->is_primary);
    }

    public function test_membership_enabled_effective_is_the_and_of_both_switches(): void
    {
        foreach ([
            'both off' => [false, false, false],
            'global only' => [true, false, false],
            'brand only' => [false, true, false],
            'both on' => [true, true, true],
        ] as $label => [$global, $brand, $expected]) {
            PlatformSettings::current()->update(['membership_enabled' => $global]);
            $affiliate = $this->primaryAffiliate();
            $affiliate->update(['membership_enabled' => $brand]);

            $this->assertSame($expected, $affiliate->membershipEnabledEffective(), $label);
            $this->assertSame(
                $expected,
                $affiliate->membershipEnabledEffective(PlatformSettings::current()),
                "{$label} (settings passed in)",
            );
        }
    }
}
