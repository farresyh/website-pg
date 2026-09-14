<?php

namespace App\Console\Commands\Gallery;

use App\Models\AffiliateBranding;
use App\Models\GalleryImage;
use App\Models\HeroSlide;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-095 decision 6: a real one-time migration for the small number
 * of real files that exist today (confirmed via production read-only
 * SSH: 1 GalleryImage row + 2 AffiliateBranding logos, 3.9MB total),
 * rather than relying on `GalleryImage.disk`'s original "never
 * invalidate old rows" design (correct for a project with real
 * accumulated volume, not this one yet).
 *
 * `GalleryImage` rows carry their own `disk` column (updated here);
 * `AffiliateBranding.logo_path`/`favicon_path` and
 * `HeroSlide.image_path` do not — those always resolve through the
 * *current* `filesystems.gallery_disk` config, so their files are
 * copied to the same relative path on the new disk with no DB change
 * needed. Run this only after `GALLERY_DISK` has already been flipped
 * to the target disk (e.g. `r2_gallery`) — every existing URL/path
 * would otherwise 404 the instant the flip happens, before the files
 * physically exist there.
 */
class MigrateGalleryToR2Command extends Command
{
    protected $signature = 'gallery:migrate-to-r2 {--from=public} {--dry-run}';

    protected $description = 'One-time copy of existing gallery/logo/hero files from the old disk onto the current GALLERY_DISK target (ADR-095).';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) config('filesystems.gallery_disk');
        $dryRun = (bool) $this->option('dry-run');

        if ($to === $from) {
            $this->error("GALLERY_DISK is still '{$from}' — set it to the target disk (e.g. r2_gallery) before running this.");

            return self::FAILURE;
        }

        $this->info("Migrating gallery files from '{$from}' to '{$to}'".($dryRun ? ' (dry run)' : '').'...');

        $galleryCount = $this->migrateGalleryImages($from, $to, $dryRun);
        $brandingCount = $this->migratePastedDiskPaths(
            AffiliateBranding::query()->where(fn ($q) => $q->whereNotNull('logo_path')->orWhereNotNull('favicon_path'))->get(),
            ['logo_path', 'favicon_path'],
            $from,
            $to,
            $dryRun,
        );
        $heroCount = $this->migratePastedDiskPaths(
            HeroSlide::query()->whereNotNull('image_path')->get(),
            ['image_path'],
            $from,
            $to,
            $dryRun,
        );

        $this->info("Done. GalleryImage: {$galleryCount}, AffiliateBranding: {$brandingCount}, HeroSlide: {$heroCount}.");

        return self::SUCCESS;
    }

    private function migrateGalleryImages(string $from, string $to, bool $dryRun): int
    {
        $count = 0;

        foreach (GalleryImage::query()->where('disk', $from)->get() as $image) {
            if (! Storage::disk($from)->exists($image->path)) {
                $this->warn("Skipping GalleryImage#{$image->id} — {$image->path} not found on '{$from}'.");

                continue;
            }

            $this->line(" - GalleryImage#{$image->id}: {$image->path}");

            if (! $dryRun) {
                Storage::disk($to)->put($image->path, Storage::disk($from)->get($image->path), 'public');
                $image->update(['disk' => $to]);
            }

            $count++;
        }

        return $count;
    }

    /**
     * @param  iterable<Model>  $rows
     * @param  list<string>  $pathColumns
     */
    private function migratePastedDiskPaths(iterable $rows, array $pathColumns, string $from, string $to, bool $dryRun): int
    {
        $count = 0;

        foreach ($rows as $row) {
            foreach ($pathColumns as $column) {
                $path = $row->{$column};

                if ($path === null || $path === '') {
                    continue;
                }

                if (! Storage::disk($from)->exists($path)) {
                    $this->warn('Skipping '.$row::class."#{$row->id}.{$column} — {$path} not found on '{$from}'.");

                    continue;
                }

                $this->line(' - '.$row::class."#{$row->id}.{$column}: {$path}");

                if (! $dryRun) {
                    Storage::disk($to)->put($path, Storage::disk($from)->get($path), 'public');
                }

                $count++;
            }
        }

        return $count;
    }
}
