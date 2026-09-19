<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\PaymentSettlement;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SettlementFixture;
use Tests\TestCase;

class PaymentSettlementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/accounting/settlements')->assertForbidden();
    }

    public function test_store_ingests_the_uploaded_file_and_returns_the_reconciliation_result(): void
    {
        $this->actingAsAdmin();

        Order::factory()->create([
            'order_number' => 'PG-UPLOADTEST',
            'payment_gateway' => 'chip',
            'payment_ref' => 'tx-upload-1',
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => '2026-09-07 12:00:00',
            'final_amount' => 1100,
            'transaction_fee' => 100,
        ]);

        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '11.00', '1.00', '10.00', [
            ['transaction_id' => 'tx-upload-1', 'reference' => 'PG-UPLOADTEST', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-07 12:32'],
        ]);
        $file = new UploadedFile($path, 'settlement.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->postJson('/api/accounting/settlements', ['file' => $file])->assertCreated();

        $response->assertJsonPath('newly_matched_count', 1);
        $response->assertJsonPath('settlement.date_from', '2026-09-07');
        $this->assertSame(1, PaymentSettlement::query()->count());

        unlink($path);
    }

    public function test_store_rejects_a_non_xlsx_file(): void
    {
        $this->actingAsAdmin();

        $file = UploadedFile::fake()->create('settlement.pdf', 10, 'application/pdf');

        $this->postJson('/api/accounting/settlements', ['file' => $file])->assertStatus(422);
    }

    public function test_index_lists_settlements_newest_first(): void
    {
        $this->actingAsAdmin();
        PaymentSettlement::query()->create($this->settlementAttributes(['date_from' => '2026-09-01', 'date_to' => '2026-09-07']));
        PaymentSettlement::query()->create($this->settlementAttributes(['date_from' => '2026-09-08', 'date_to' => '2026-09-14']));

        $response = $this->getJson('/api/accounting/settlements')->assertOk();

        $this->assertSame('2026-09-08', $response->json('0.date_from'));
    }

    public function test_update_records_the_founders_own_bank_figure(): void
    {
        $this->actingAsAdmin();
        $settlement = PaymentSettlement::query()->create($this->settlementAttributes());

        $response = $this->patchJson("/api/accounting/settlements/{$settlement->id}", [
            'actual_bank_amount_sen' => 1000,
            'status' => 'matched',
        ])->assertOk();

        $response->assertJsonPath('status', 'matched');
        $response->assertJsonPath('actual_bank_amount_sen', 1000);
    }

    public function test_update_requires_a_variance_note_when_status_is_variance(): void
    {
        $this->actingAsAdmin();
        $settlement = PaymentSettlement::query()->create($this->settlementAttributes());

        $this->patchJson("/api/accounting/settlements/{$settlement->id}", [
            'actual_bank_amount_sen' => 900,
            'status' => 'variance',
        ])->assertStatus(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function settlementAttributes(array $overrides = []): array
    {
        return array_merge([
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-07',
            'expected_gross_sen' => 1100,
            'expected_fee_sen' => 100,
            'expected_net_sen' => 1000,
            'file_gross_sen' => 1100,
            'file_fee_sen' => 100,
            'file_net_sen' => 1000,
            'status' => 'pending',
            'original_filename' => 'settlement.xlsx',
        ], $overrides);
    }
}
