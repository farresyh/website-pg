<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\CurrencyRate;
use App\Models\Supplier;
use App\Models\SupplierTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-134 decisions 3, 15–17: a transfer's stored rate carries its fee when
 * pooled, a rate far from the market that day needs the admin's
 * confirmation (it now prices the whole catalogue), and a supplier with
 * deposits keeps its currency.
 */
class TransferSpreadGuardTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
        $this->supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        CurrencyRate::query()->create(['from' => 'IDR', 'to' => 'MYR', 'rate' => 0.000224, 'source' => 'open.er-api.com', 'fetched_at' => '2026-10-29 00:30:00']);
    }

    private function record(array $overrides = []): TestResponse
    {
        return $this->postJson("/api/accounting/suppliers/{$this->supplier->id}/transfers", array_merge([
            'transferred_on' => '2026-10-29', 'source_channel' => 'bank', 'currency' => 'IDR',
            'amount_myr_sent' => 499811, 'fee_myr' => 1466, 'amount_foreign_received' => 21000482,
        ], $overrides));
    }

    /** Maybank's 29 Oct transfer: +6.56% against the market that day, inside the default band, saved with its fee in the rate. */
    public function test_a_pooled_transfers_rate_carries_its_fee_and_saves_inside_the_band(): void
    {
        $this->record()->assertCreated();

        $this->assertSame(number_format((499811 + 1466) / 100 / 21000482, 8, '.', ''), SupplierTransfer::query()->sole()->effective_rate);
    }

    public function test_a_transfer_before_the_pool_opened_keeps_its_fee_out_of_the_rate(): void
    {
        $this->record(['transferred_on' => '2026-09-21', 'amount_myr_sent' => 33613, 'fee_myr' => 766, 'amount_foreign_received' => 1457203])->assertCreated();

        $this->assertSame(number_format(336.13 / 1457203, 8, '.', ''), SupplierTransfer::query()->sole()->effective_rate);
    }

    /** A typo (RM5,998 for RM4,998) is +27.8%: refused until confirmed, and nothing is written meanwhile. */
    public function test_a_rate_outside_the_band_needs_confirmation(): void
    {
        $this->record(['amount_myr_sent' => 599811])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_spread');
        $this->assertSame(0, SupplierTransfer::query()->count());

        $this->record(['amount_myr_sent' => 599811, 'confirm_spread' => true])->assertCreated();
    }

    public function test_the_band_is_the_suppliers_own_when_set_in_middleware(): void
    {
        $this->supplier->update(['api_config' => ['transfer_spread_min_pct' => '-1', 'transfer_spread_max_pct' => '5']]);

        $this->record()->assertUnprocessable()->assertJsonValidationErrors('confirm_spread');   // +6.56% > 5%
    }

    public function test_with_no_market_rate_to_compare_the_transfer_saves(): void
    {
        CurrencyRate::query()->delete();

        $this->record(['amount_myr_sent' => 599811])->assertCreated();
    }

    /** A correction that moves the rate is checked too, and recomputes the stored rate with the fee. */
    public function test_a_correction_is_checked_and_restates_the_rate(): void
    {
        $this->record()->assertCreated();
        $transfer = SupplierTransfer::query()->sole();

        $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/correct", ['amount_myr_sent' => 599811, 'reason' => 'typo'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_spread');

        $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/correct", ['fee_myr' => 2000, 'reason' => 'bank charged more'])
            ->assertCreated();
        $this->assertSame(number_format((499811 + 2000) / 100 / 21000482, 8, '.', ''), $transfer->fresh()->effective_rate);
    }

    /** Decision 17: deposits and packages are counted in the supplier's currency. */
    public function test_a_supplier_with_transfers_keeps_its_currency(): void
    {
        $this->record()->assertCreated();
        $this->withSession(['mfa_stepped_up_at' => now()->timestamp]);

        $this->putJson("/api/middleware/suppliers/{$this->supplier->id}", ['currency' => 'MYR'])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertSame('IDR', $this->supplier->fresh()->currency);
    }
}
