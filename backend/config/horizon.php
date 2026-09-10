<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:revalidation' => 60,
        'redis:orders' => 60,
        'redis:price-sync' => 300,
        'redis:backups' => 300,
        'redis:supplier-request-logs' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    // ADR-048 decision 3: one supervisor per queue, mirroring the three
    // dedicated queue-worker-* containers this replaces in
    // docker-compose.prod.yml (ADR-020 decision #5's head-of-line-blocking
    // isolation — a slow price-sync/backup run must never starve an
    // urgent order job, or vice versa). `balance: off` since each
    // supervisor already owns exactly one queue — nothing to balance
    // across. tries/timeout match each queue-worker-*'s own prior flags
    // exactly (orders: --tries=3, price-sync/backups: --tries=1, all
    // three: --sleep=3, Horizon's per-supervisor default). maxProcesses
    // stays 1 everywhere — zero real production traffic yet (ADR-021) —
    // scale per-supervisor here once real order volume justifies it, no
    // container/compose change needed to do that (Horizon manages its
    // own worker count, unlike the old `docker compose --scale` pattern).
    'defaults' => [
        'supervisor-orders' => [
            'connection' => 'redis',
            'queue' => ['orders'],
            'balance' => 'off',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-price-sync' => [
            'connection' => 'redis',
            'queue' => ['price-sync'],
            'balance' => 'off',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-revalidation' => [
            'connection' => 'redis',
            'queue' => ['revalidation'],
            'balance' => 'off',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-backups' => [
            'connection' => 'redis',
            'queue' => ['backups'],
            'balance' => 'off',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        // ADR-051 — its own supervisor, same isolation reasoning as
        // every queue above: a burst of supplier calls logging
        // themselves must never sit in front of (or behind) an order
        // job. tries=1 matches LogSupplierRequestJob's own $tries —
        // losing an occasional debug-log row isn't worth a retry.
        'supervisor-supplier-request-logs' => [
            'connection' => 'redis',
            'queue' => ['supplier-request-logs'],
            'balance' => 'off',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 30,
            'nice' => 0,
        ],
        // ADR-084 PR-3 decision 4 — the reseller delivery webhook's own
        // supervisor, same isolation reasoning as every queue above: a
        // reseller endpoint that is slow, hanging, or 5xx-ing through its
        // ~1-hour retry schedule must never sit in front of an order job.
        // tries=5 matches DeliverResellerWebhook's own $tries (the job's
        // backoff() spaces the attempts); timeout comfortably over the
        // job's own 10s HTTP timeout + 5s connect timeout.
        'supervisor-reseller-webhooks' => [
            'connection' => 'redis',
            'queue' => ['reseller-webhooks'],
            'balance' => 'off',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 5,
            'timeout' => 30,
            'nice' => 0,
        ],
        // The catch-all. Every job/listener above names its own queue via
        // onQueue()/broadcastQueue()/$queue, but a class that forgets to
        // (SendMembershipReceiptJob did — its receipt emails silently had
        // no worker in production until this was added; found during
        // ADR-077 PR-3) lands on 'default', and so do Laravel's own
        // framework jobs and any package job. Without this supervisor
        // those sit unprocessed forever with no error. tries=3/timeout=60
        // mirror supervisor-orders — the safe general default.
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'off',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-orders' => ['maxProcesses' => 1],
            'supervisor-price-sync' => ['maxProcesses' => 1],
            'supervisor-revalidation' => ['maxProcesses' => 1],
            'supervisor-backups' => ['maxProcesses' => 1],
            'supervisor-supplier-request-logs' => ['maxProcesses' => 1],
            'supervisor-reseller-webhooks' => ['maxProcesses' => 1],
            'supervisor-default' => ['maxProcesses' => 1],
        ],

        'local' => [
            'supervisor-orders' => ['maxProcesses' => 1],
            'supervisor-price-sync' => ['maxProcesses' => 1],
            'supervisor-revalidation' => ['maxProcesses' => 1],
            'supervisor-backups' => ['maxProcesses' => 1],
            'supervisor-supplier-request-logs' => ['maxProcesses' => 1],
            'supervisor-reseller-webhooks' => ['maxProcesses' => 1],
            'supervisor-default' => ['maxProcesses' => 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
