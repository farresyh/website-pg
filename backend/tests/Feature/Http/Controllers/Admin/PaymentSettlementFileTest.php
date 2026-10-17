<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\PaymentSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SettlementFixture;
use Tests\TestCase;

/**
 * §16 item 120 (ADR-083 2026-10-18 addendum): the original CHIP
 * settlement file is kept and downloadable, and a settlement uploaded
 * before files were kept can take its original, only if it really is it.
 */
class PaymentSettlementFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.accounting_disk'));
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
    }

    /** A settlement as one uploaded before item 120: recorded totals, no file. */
    private function oldSettlement(): PaymentSettlement
    {
        return PaymentSettlement::query()->create([
            'date_from' => '2026-09-03', 'date_to' => '2026-09-19',
            'matched_gross_sen' => 1100, 'matched_fee_sen' => 100, 'matched_net_sen' => 1000,
            'file_gross_sen' => 1100, 'file_fee_sen' => 100, 'file_net_sen' => 1000,
            'status' => 'matched', 'original_filename' => 'settlements-20260919190431.xlsx',
        ]);
    }

    private function upload(string $totalAmount = '11.00', string $range = '2026-09-03 to 2026-09-19'): UploadedFile
    {
        $path = SettlementFixture::build($range, $totalAmount, '1.00', number_format((float) $totalAmount - 1, 2, '.', ''), [
            ['transaction_id' => 'tx-'.uniqid(), 'reference' => 'PG-X', 'amount' => $totalAmount, 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-19 12:00'],
        ]);

        return new UploadedFile($path, 'settlements-20260919190431.xlsx', null, null, true);
    }

    public function test_an_upload_keeps_the_file_and_it_downloads_under_its_original_name(): void
    {
        $id = $this->postJson('/api/accounting/settlements', ['file' => $this->upload()])->assertCreated()->json('settlement.id');

        $this->assertNotNull(PaymentSettlement::query()->find($id)->file_path);
        $this->get("/api/accounting/settlements/{$id}/file")
            ->assertOk()
            ->assertDownload('settlements-20260919190431.xlsx');
    }

    public function test_the_same_file_again_is_a_422_naming_the_settlement(): void
    {
        $file = $this->upload();
        $id = $this->postJson('/api/accounting/settlements', ['file' => $file])->assertCreated()->json('settlement.id');

        $this->postJson('/api/accounting/settlements', ['file' => new UploadedFile($file->getRealPath(), 'again.xlsx', null, null, true)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', "Every transaction in this file is already reconciled (settlement #{$id}). Nothing was recorded.");
    }

    public function test_a_settlement_without_a_file_has_no_download(): void
    {
        $this->get("/api/accounting/settlements/{$this->oldSettlement()->id}/file")->assertNotFound();
    }

    public function test_the_original_attaches_to_an_old_settlement_when_dates_and_totals_match(): void
    {
        $settlement = $this->oldSettlement();
        $file = $this->upload();

        $this->postJson("/api/accounting/settlements/{$settlement->id}/file", ['file' => $file])->assertOk();

        $settlement->refresh();
        $this->assertStringStartsWith('accounting/chip-settlements/2026/09/', $settlement->file_path);
        $this->assertSame(hash_file('sha256', $file->getRealPath()), $settlement->file_sha256);
        $this->assertSame(1100, $settlement->matched_gross_sen, 'attaching never touches the reconciled figures');
        Storage::disk(config('filesystems.accounting_disk'))->assertExists($settlement->file_path);
    }

    public function test_a_file_with_different_totals_is_not_attached(): void
    {
        $settlement = $this->oldSettlement();

        $this->postJson("/api/accounting/settlements/{$settlement->id}/file", ['file' => $this->upload(totalAmount: '12.00')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertNull($settlement->fresh()->file_path);
        $this->assertSame([], Storage::disk(config('filesystems.accounting_disk'))->allFiles());
    }

    public function test_a_file_with_different_dates_is_not_attached(): void
    {
        $settlement = $this->oldSettlement();

        $this->postJson("/api/accounting/settlements/{$settlement->id}/file", ['file' => $this->upload(range: '2026-09-03 to 2026-09-20')])
            ->assertUnprocessable();

        $this->assertNull($settlement->fresh()->file_path);
    }

    public function test_a_settlement_that_already_has_its_file_refuses_another(): void
    {
        $settlement = $this->oldSettlement();
        $this->postJson("/api/accounting/settlements/{$settlement->id}/file", ['file' => $this->upload()])->assertOk();
        $path = $settlement->fresh()->file_path;

        $this->postJson("/api/accounting/settlements/{$settlement->id}/file", ['file' => $this->upload()])->assertUnprocessable();

        $this->assertSame($path, $settlement->fresh()->file_path);
        $this->assertCount(1, Storage::disk(config('filesystems.accounting_disk'))->allFiles());
    }

    public function test_one_file_cannot_be_attached_to_two_settlements(): void
    {
        $file = $this->upload();
        // A second request carries its own UploadedFile (and storage name) for the same bytes.
        $sameBytes = new UploadedFile($file->getRealPath(), $file->getClientOriginalName(), null, null, true);
        $this->postJson("/api/accounting/settlements/{$this->oldSettlement()->id}/file", ['file' => $file])->assertOk();

        $this->postJson("/api/accounting/settlements/{$this->oldSettlement()->id}/file", ['file' => $sameBytes])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'This exact file is already attached to another settlement.');

        $this->assertCount(1, Storage::disk(config('filesystems.accounting_disk'))->allFiles());
    }
}
