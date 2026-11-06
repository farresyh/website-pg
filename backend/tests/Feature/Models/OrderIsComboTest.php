<?php

namespace Tests\Feature\Models;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\TestCase;

/** ADR-103 decision 17: one answer to "is this order a combo", legs or no legs. */
class OrderIsComboTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use RefreshDatabase;

    private function comboWithoutLegs(): Order
    {
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 0, 'cost_price' => 0, 'standard_selling_price' => 0, 'markup_percent' => 0,
        ]);

        return $this->order([
            'game_id' => $game->id, 'package_id' => $combo->id, 'supplier_id' => null,
            'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);
    }

    public function test_a_combo_with_no_legs_is_still_a_combo(): void
    {
        $this->assertTrue($this->comboWithoutLegs()->isCombo());
        $this->assertFalse($this->order()->isCombo());
    }

    public function test_a_combo_with_no_legs_cannot_be_marked_delivered(): void
    {
        $this->actingAsAdmin();
        $order = $this->comboWithoutLegs();

        $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'GV-1', 'supplier_price' => 4.75])
            ->assertUnprocessable();

        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
    }

    public function test_the_admin_detail_says_whether_it_is_a_combo(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/orders/'.$this->comboWithoutLegs()->id)->assertOk()->assertJsonPath('is_combo', true);
        $this->getJson('/api/orders/'.$this->order()->id)->assertOk()->assertJsonPath('is_combo', false);
    }

    public function test_nothing_in_app_reads_the_package_flag_around_is_combo(): void
    {
        $offenders = collect(File::allFiles(app_path()))
            ->filter(fn (SplFileInfo $file) => str_contains($file->getContents(), 'package?->is_combo'))
            ->map(fn (SplFileInfo $file) => $file->getRelativePathname())
            ->reject(fn (string $path) => $path === 'Models/Order.php')
            ->values()->all();

        $this->assertSame([], $offenders);
        $this->assertSame(1, substr_count(File::get(app_path('Models/Order.php')), 'package?->is_combo'));
    }
}
