<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * ADR-039 decision 4, enforced by its 2026-10-22 addendum: every archive is
 * encrypted with BACKUP_ARCHIVE_PASSWORD. Prod ran without it from go-live
 * to 2026-10-22, and spatie then writes a plain zip without a word. So in
 * production a missing password fails the run (email + /api/health) instead.
 * Local and CI may run unencrypted.
 */
final class BackupArchivePassword
{
    public static function require(): ?string
    {
        $password = config('backup.backup.password');

        if (blank($password) && app()->isProduction()) {
            throw new RuntimeException('BACKUP_ARCHIVE_PASSWORD is not set: refusing to write an unencrypted backup (ADR-039 decision 4).');
        }

        return blank($password) ? null : (string) $password;
    }
}
