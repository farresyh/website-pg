<?php

namespace Tests\Feature\Services\Accounting;

use App\Services\Accounting\AccountingFileStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/** §16 item 120: every accounting source file is filed by its accounting month and never left orphaned. */
class AccountingFileStoreTest extends TestCase
{
    public function test_a_file_is_filed_under_the_category_year_and_month_of_its_accounting_date(): void
    {
        Storage::fake('local');

        $path = AccountingFileStore::storeThen(UploadedFile::fake()->create('r.pdf'), 'local', 'accounting/x', '2026-09-30', fn (?string $p) => $p);

        $this->assertStringStartsWith('accounting/x/2026/09/', $path);
        Storage::disk('local')->assertExists($path);
    }

    /** An instant is read as a Malaysia date: 1 Oct 01:00 KL is still 30 Sep in UTC. */
    public function test_an_instant_is_filed_by_its_malaysia_date(): void
    {
        Storage::fake('local');

        $path = AccountingFileStore::storeThen(UploadedFile::fake()->create('r.pdf'), 'local', 'accounting/x', CarbonImmutable::parse('2026-09-30 17:00:00', 'UTC'), fn (?string $p) => $p);

        $this->assertStringStartsWith('accounting/x/2026/10/', $path);
    }

    public function test_the_stored_file_is_deleted_when_the_write_fails(): void
    {
        Storage::fake('local');
        $stored = null;

        try {
            AccountingFileStore::storeThen(UploadedFile::fake()->create('r.pdf'), 'local', 'accounting/x', '2026-09-30', function (?string $p) use (&$stored) {
                $stored = $p;
                throw new RuntimeException('db write failed');
            });
            $this->fail('the write error must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('db write failed', $e->getMessage());
        }

        $this->assertNotNull($stored);
        Storage::disk('local')->assertMissing($stored);
    }

    public function test_no_file_runs_the_write_with_a_null_path(): void
    {
        $this->assertSame('ran', AccountingFileStore::storeThen(null, 'local', 'accounting/x', '2026-09-30', fn (?string $p) => $p ?? 'ran'));
    }
}
