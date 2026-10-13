<?php

namespace App\Support;

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
}
