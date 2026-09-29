<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * ADR-014: distinct from Laravel's own built-in `/up` route (which
 * only proves the app booted) — this checks the two dependencies
 * FulfillOrderJob actually needs to run: a live DB connection and a
 * reachable queue connection. Unauthenticated by design, same as any
 * infra health-check endpoint (nothing here reveals customer/order
 * data).
 *
 * `horizon` (2026-09-29 pre-release review): a reachable Redis doesn't
 * mean anything is working the queue. With Horizon down no order is
 * fulfilled and its own LongWait alert dies with it, so this endpoint
 * (the uptime monitor's target) has to catch it. Only checked when the
 * queue actually runs on Redis — sync/database drivers have no Horizon.
 */
class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'queue' => $this->checkQueue(),
            'horizon' => config('queue.default') !== 'redis' || $this->checkHorizon(),
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

    private function checkHorizon(): bool
    {
        try {
            return app(MasterSupervisorRepository::class)->all() !== [];
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
