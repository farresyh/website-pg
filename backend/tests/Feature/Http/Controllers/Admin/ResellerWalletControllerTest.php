<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\WalletTopupReceipt;
use App\Services\Ledger\LedgerOwnerType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-073 decision 3(b) (PR-C): admin manual-credit of a Reseller wallet.
 */
class ResellerWalletControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function makeReseller(): Reseller
    {
        return Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $reseller = $this->makeReseller();

        $this->getJson("/api/resellers/{$reseller->id}/wallet")->assertForbidden();
    }

    public function test_credit_without_receipt_credits_the_ledger(): void
    {
        $admin = $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();

        $response = $this->postJson("/api/resellers/{$reseller->id}/wallet/credit", [
            'amount_sen' => 5000,
            'note' => 'Bank transfer, ref #123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('balance_sen', 5000)
            ->assertJsonPath('entry.type', 'wallet_topup')
            ->assertJsonPath('entry.amount', 5000)
            ->assertJsonPath('entry.reference_type', null)
            ->assertJsonPath('entry.reason', 'Bank transfer, ref #123');

        $this->assertDatabaseHas('ledger_entries', [
            'owner_type' => LedgerOwnerType::ResellerWallet->value,
            'owner_id' => $reseller->id,
            'type' => 'wallet_topup',
            'amount' => 5000,
            'created_by' => $admin->id,
        ]);
    }

    public function test_credit_with_receipt_stores_the_file_and_links_it(): void
    {
        Storage::fake('local');
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();

        $response = $this->postJson("/api/resellers/{$reseller->id}/wallet/credit", [
            'amount_sen' => 10000,
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf'),
        ]);

        $response->assertCreated()->assertJsonPath('entry.reference_type', 'wallet_topup_receipt');

        $receipt = WalletTopupReceipt::query()->firstOrFail();
        $this->assertSame('receipt.pdf', $receipt->original_name);
        $this->assertSame('local', $receipt->disk);
        Storage::disk('local')->assertExists($receipt->path);
        $response->assertJsonPath('entry.reference_id', $receipt->id);
    }

    public function test_credit_rejects_zero_or_negative_amount(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();

        $this->postJson("/api/resellers/{$reseller->id}/wallet/credit", ['amount_sen' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_sen');
    }

    public function test_credit_rejects_a_non_receipt_file_type(): void
    {
        Storage::fake('local');
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();

        $this->postJson("/api/resellers/{$reseller->id}/wallet/credit", [
            'amount_sen' => 1000,
            'receipt' => UploadedFile::fake()->create('malware.exe', 10),
        ])->assertUnprocessable()->assertJsonValidationErrors('receipt');
    }

    public function test_index_returns_balance_and_ledger_history(): void
    {
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();
        $this->postJson("/api/resellers/{$reseller->id}/wallet/credit", ['amount_sen' => 2000])->assertCreated();
        $this->postJson("/api/resellers/{$reseller->id}/wallet/credit", ['amount_sen' => 3000])->assertCreated();

        $response = $this->getJson("/api/resellers/{$reseller->id}/wallet");

        $response->assertOk()->assertJsonPath('balance_sen', 5000);
        $this->assertCount(2, $response->json('entries.data'));
    }

    public function test_index_batch_loads_receipt_names_for_entries_that_have_one(): void
    {
        Storage::fake('local');
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();
        $this->postJson("/api/resellers/{$reseller->id}/wallet/credit", ['amount_sen' => 1000])->assertCreated();
        $this->postJson("/api/resellers/{$reseller->id}/wallet/credit", [
            'amount_sen' => 2000,
            'receipt' => UploadedFile::fake()->create('bank-slip.pdf', 30, 'application/pdf'),
        ])->assertCreated();

        $response = $this->getJson("/api/resellers/{$reseller->id}/wallet");

        $entries = collect($response->json('entries.data'));
        $this->assertNull($entries->firstWhere('amount', 1000)['receipt_name']);
        $this->assertSame('bank-slip.pdf', $entries->firstWhere('amount', 2000)['receipt_name']);
    }

    public function test_download_receipt_streams_the_file(): void
    {
        Storage::fake('local');
        $this->actAsSuperAdmin();
        $reseller = $this->makeReseller();
        $this->postJson("/api/resellers/{$reseller->id}/wallet/credit", [
            'amount_sen' => 1000,
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 50, 'application/pdf'),
        ])->assertCreated();
        $receipt = WalletTopupReceipt::query()->firstOrFail();

        $response = $this->get("/api/wallet-topup-receipts/{$receipt->id}/download");

        $response->assertOk();
    }
}
