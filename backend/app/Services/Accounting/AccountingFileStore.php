<?php

namespace App\Services\Accounting;

use App\Support\MalaysiaTime;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * §16 item 120 (ADR-083 2026-10-18 addendum): the one way an accounting
 * source file (CHIP settlement, supplier receipt, envelope receipt,
 * wallet credit receipt) is stored.
 *
 * - Filed under `<category>/YYYY/MM/` by its accounting date (a Malaysia
 *   date), so the bucket browses by the month an accountant asks for.
 *   The path stored on the row is the source of truth; the folder is a
 *   convenience and never moves when that date is later corrected.
 * - Stored before the DB write and deleted if that write throws, so a
 *   failed or replayed save never leaves a file no row points at. A
 *   committed file is never deleted (LHDN: keep seven years).
 */
final class AccountingFileStore
{
    /**
     * @template T
     *
     * @param  Closure(?string): T  $write  receives the stored path (null when no file)
     * @return T
     */
    public static function storeThen(?UploadedFile $file, string $disk, string $category, CarbonInterface|string $accountingDate, Closure $write): mixed
    {
        $path = $file?->store(self::folder($category, $accountingDate), $disk);

        try {
            return $write($path);
        } catch (Throwable $e) {
            // A storage error here must not replace $e.
            if ($path !== null) {
                rescue(fn () => Storage::disk($disk)->delete($path), report: false);
            }

            throw $e;
        }
    }

    private static function folder(string $category, CarbonInterface|string $accountingDate): string
    {
        $date = $accountingDate instanceof CarbonInterface
            ? $accountingDate->copy()->setTimezone(MalaysiaTime::TIMEZONE)
            : Carbon::parse($accountingDate);

        return $category.'/'.$date->format('Y/m');
    }
}
