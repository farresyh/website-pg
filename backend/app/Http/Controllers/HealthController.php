<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * ADR-014: distinct from Laravel's own built-in `/up` route (which
 * only proves the app booted) — this checks the two dependencies
 * FulfillOrderJob actually needs to run: a live DB connection and a
 * reachable queue connection. Unauthenticated by design, same as any
 * infra health-check endpoint (nothing here reveals customer/order
 * data).
 */
class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'queue' => $this->checkQueue(),
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json(
            ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks],
            $healthy ? 200 : 503,
        );
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function checkQueue(): bool
    {
        try {
            Queue::size();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
