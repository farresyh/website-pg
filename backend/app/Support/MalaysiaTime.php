<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The business's own timezone (Malaysia, fixed UTC+8, no DST). Every
 * "today", day bucket, month boundary and date-only input is read in it;
 * the app itself stays on UTC (config/app.php). The SQL day-bucket
 * expressions still say '+08:00' directly, since MySQL's CONVERT_TZ needs
 * an offset unless the tz tables are loaded.
 */
final class MalaysiaTime
{
    public const TIMEZONE = 'Asia/Kuala_Lumpur';

    /** SQL for the Malaysia calendar date (Y-m-d) of a UTC timestamp column. */
    public static function dateSql(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "date({$column}, '+8 hours')",
            default => "DATE(CONVERT_TZ({$column}, '+00:00', '+08:00'))",
        };
    }
}
