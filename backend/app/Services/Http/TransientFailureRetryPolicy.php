<?php

namespace App\Services\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * ADR-014: shared retry predicate for every outbound supplier/gateway
 * call (GamevionAdapter, XenditGateway) — retry a connection failure
 * or a real 5xx server error, never a 4xx. A 4xx (e.g. Gamevion's
 * `422 invalid product code`) is a validation outcome, not a
 * transient failure; retrying it only burns the retry budget and
 * delays the real failure signal reaching the caller.
 */
final class TransientFailureRetryPolicy
{
    public static function shouldRetry(): callable
    {
        return function (Throwable $exception): bool {
            if ($exception instanceof ConnectionException) {
                return true;
            }

            return $exception instanceof RequestException && $exception->response->serverError();
        };
    }
}
