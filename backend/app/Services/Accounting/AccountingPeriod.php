<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriodClose;
use App\Support\MalaysiaTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ADR-127 decision 4: a closed month cannot change. Months close in
 * order (`MonthCloseService` refuses a gap), so a date is in a closed
 * month when its Kuala Lumpur month is at or before the latest active
 * close. Every writer that takes an accounting date from a person calls
 * `assertOpen()`; to change a closed month, reopen it first.
 */
final class AccountingPeriod
{
    public static function isClosed(CarbonInterface|string $date): bool
    {
        $latest = AccountingPeriodClose::query()->active()->max('period_month');

        return $latest !== null && self::monthStart($date) <= Carbon::parse($latest)->toDateString();
    }

    /** @param  string  $field  the request field the date came from, for the validation error */
    public static function assertOpen(CarbonInterface|string $date, string $field): void
    {
        if (self::isClosed($date)) {
            throw ValidationException::withMessages([
                $field => [Carbon::parse(self::monthStart($date))->format('F Y').' is closed. Reopen it in Accounting › Month close to change it.'],
            ]);
        }
    }

    /** A plain date (`Y-m-d`) is already a Kuala Lumpur day; an instant is converted. */
    private static function monthStart(CarbonInterface|string $date): string
    {
        $day = is_string($date) && strlen($date) === 10
            ? Carbon::parse($date)
            : Carbon::parse($date)->setTimezone(MalaysiaTime::TIMEZONE);

        return $day->startOfMonth()->toDateString();
    }
}
