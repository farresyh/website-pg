<?php

namespace Tests\Feature\Models;

use App\Models\Reseller;
use App\Models\ResellerTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_active_casts_to_bool(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => 1]);

        $this->assertTrue($reseller->fresh()->is_active);
    }

    public function test_tier_relation_resolves(): void
    {
        $tier = ResellerTier::query()->create([
            'name' => 'Gold', 'markup_percent' => 8, 'is_active' => true, 'sort_order' => 1,
        ]);
        $reseller = Reseller::query()->create([
            'business_name' => 'Acme', 'reseller_tier_id' => $tier->id, 'is_active' => true,
        ]);

        $this->assertSame('Gold', $reseller->tier->name);
    }

    public function test_soft_delete_keeps_the_row(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        $reseller->delete();

        $this->assertSoftDeleted('resellers', ['id' => $reseller->id]);
    }
}
