<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Gallery Upload Disk
    |--------------------------------------------------------------------------
    |
    | Admin\GalleryImageController (IMG-1/IMG-2) needs a disk that always
    | serves a public URL, independent of whatever "default" above is set
    | to for the rest of the app (this env already sets FILESYSTEM_DISK to
    | "local", which has no public URL — reusing "default" here would
    | silently break gallery uploads). ADR-019: was hardcoded to the
    | literal string 'public'; now a real env-driven config value, so the
    | later swap to "s3" (once a bucket exists) is a config change, not a
    | code change.
    |
    */

    'gallery_disk' => env('GALLERY_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Backup Disk
    |--------------------------------------------------------------------------
    |
    | ADR-039 decision 3: the disk `config/backup.php` and
    | Middleware\BackupController write/read backup archives on/from —
    | deliberately private (never "public", unlike gallery_disk above),
    | since these archives hold real customer PII and money/order
    | history. Local by default; swap to "s3" once a real bucket exists
    | (ADR-020) via env only, no code change.
    |
    */

    'backup_disk' => env('BACKUP_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Wallet Top-Up Receipt Disk
    |--------------------------------------------------------------------------
    |
    | ADR-073 decision 3(b): the optional receipt/proof file an admin
    | attaches to a manual `Reseller` (wallet) top-up, for audit — same
    | "deliberately private" reasoning as backup_disk above (a real bank
    | transfer receipt, not something to serve at a guessable public URL).
    | Local by default; swap to "s3"/"r2" via env only, no code change.
    |
    */

    'wallet_receipts_disk' => env('WALLET_RECEIPTS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Accounting Attachment Disk
    |--------------------------------------------------------------------------
    |
    | ADR-083 decision 10: supplier-transfer receipts and any future
    | accounting attachment (CHIP settlement files, etc.) — deliberately
    | private, same reasoning as backup_disk/wallet_receipts_disk above.
    | Local by default; swap to "s3"/"r2" via env only, no code change.
    |
    */

    'accounting_disk' => env('ACCOUNTING_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * ADR-095: Cloudflare R2 (S3-compatible), the `gallery_disk` target
         * once `GALLERY_DISK=r2_gallery` — a separate, own-named disk
         * rather than repurposing the generic `s3` block above, since R2
         * needs its own endpoint/credential shape, not AWS's. Public: the
         * bucket is fronted by the `cdn.pekangame.space` custom domain
         * (own scoped API token, not the backups token below) — `url`
         * points there directly so `Storage::disk('r2_gallery')->url()`
         * never routes through the raw R2 endpoint.
         */
        'r2_gallery' => [
            'driver' => 's3',
            'key' => env('R2_GALLERY_ACCESS_KEY_ID'),
            'secret' => env('R2_GALLERY_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_GALLERY_BUCKET'),
            'url' => env('R2_GALLERY_PUBLIC_URL'),
            'endpoint' => env('R2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * ADR-095: the `backup_disk` target once `BACKUP_DISK=r2_backups`
         * — deliberately no `url` key at all (unlike `r2_gallery` above):
         * this bucket has no custom domain, no public access, and never
         * needs one. Own scoped API token, separate from `r2_gallery`'s —
         * least-privilege, and a misconfigured public-access setting on
         * one bucket can never expose the other.
         */
        'r2_backups' => [
            'driver' => 's3',
            'key' => env('R2_BACKUPS_ACCESS_KEY_ID'),
            'secret' => env('R2_BACKUPS_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_BACKUPS_BUCKET'),
            'endpoint' => env('R2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
