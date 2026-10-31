<?php

namespace App\Services\PartnerApplication;

use App\Models\PartnerApplication;
use App\Models\PartnerApplicationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ADR-133 decision 8 (and its 2026-10-31 addendum): the KYC files of an application, on the
 * private `filesystems.kyc_disk`. The file type and size are checked by the request (Laravel's
 * `mimes` rule reads the content, not the extension); this class owns which kinds an applicant
 * may upload and how many of each.
 */
final class PartnerApplicationDocuments
{
    /** Kind => how many files of it an application holds. */
    public const LIMITS = ['ssm' => 1, 'ic_front' => 1, 'ic_back' => 1, 'bank_statement' => 2];

    /** What each applicant type must upload before submitting (at least one of each). */
    public const REQUIRED = [
        PartnerApplication::TYPE_COMPANY => ['ssm', 'bank_statement'],
        PartnerApplication::TYPE_INDIVIDUAL => ['ic_front', 'ic_back', 'bank_statement'],
    ];

    /**
     * A single-file kind replaces the earlier file; a bank statement past its two files is refused.
     * The file is stored before the row and removed again if the row write fails, so a failed save
     * never leaves a file no row points at (the AccountingFileStore rule).
     */
    public function store(PartnerApplication $application, string $kind, UploadedFile $file): PartnerApplicationDocument
    {
        if (! in_array($kind, self::REQUIRED[$application->applicant_type], true)) {
            throw ValidationException::withMessages(['kind' => ['This document is not asked for your application type.']]);
        }

        $disk = (string) config('filesystems.kyc_disk');
        $path = $file->store('kyc/'.$application->id, $disk);

        try {
            return DB::transaction(function () use ($application, $kind, $file, $disk, $path) {
                // Under the application's lock, so an upload racing the submit cannot add a file
                // to an application that has just been locked.
                if (PartnerApplication::query()->lockForUpdate()->find($application->id)?->status !== PartnerApplicationStatus::Invited) {
                    throw ValidationException::withMessages(['file' => ['This application has already been submitted.']]);
                }

                $existing = $application->documents()->where('kind', $kind)->lockForUpdate()->get();

                if ($existing->count() >= self::LIMITS[$kind]) {
                    if (self::LIMITS[$kind] > 1) {
                        throw ValidationException::withMessages(['file' => ['You have uploaded the most files for this document. Remove one first.']]);
                    }
                    $existing->each(fn (PartnerApplicationDocument $old) => $this->delete($old));
                }

                return $application->documents()->create([
                    'kind' => $kind,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                ]);
            });
        } catch (Throwable $e) {
            rescue(fn () => Storage::disk($disk)->delete($path), report: false);

            throw $e;
        }
    }

    /**
     * The row goes first and the file once the row's delete commits (at once outside a
     * transaction), so a rolled-back replace keeps the old file. A file left behind by a storage
     * error is private and harmless.
     */
    public function delete(PartnerApplicationDocument $document): void
    {
        $document->delete();
        DB::afterCommit(fn () => rescue(fn () => Storage::disk($document->disk)->delete($document->path), report: false));
    }

    public function deleteAllFor(PartnerApplication $application): void
    {
        $application->documents()->get()->each(fn (PartnerApplicationDocument $document) => $this->delete($document));
    }

    /** @return list<string> the required kinds this application has no file for yet */
    public function missingKinds(PartnerApplication $application): array
    {
        $have = $application->documents()->pluck('kind')->unique()->all();

        return array_values(array_diff(self::REQUIRED[$application->applicant_type], $have));
    }
}
