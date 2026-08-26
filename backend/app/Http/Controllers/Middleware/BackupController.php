<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\BackupRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-039 decision 9: `/middleware/backups` — one unified history table
 * covering every `BackupRun` regardless of trigger, a "Backup Now"
 * button (`store`), and download/delete on individual archives. No
 * restore endpoint anywhere in this controller — decision 5 keeps
 * restore CLI/artisan-only, deliberately never a self-service admin
 * action.
 */
class BackupController extends Controller
{
    /**
     * ADR-039 decision 9: history table, most recent run first — same
     * shape as PriceSyncController::index().
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);

        return response()->json(
            BackupRun::query()
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->paginate($perPage)
                ->withQueryString(),
        );
    }

    /**
     * BAK-1: total count, latest timestamp, total size.
     */
    public function stats(): JsonResponse
    {
        $lastRun = BackupRun::query()->latest()->first();

        return response()->json([
            'total_count' => BackupRun::query()->count(),
            'total_size_bytes' => (int) BackupRun::query()->whereNotNull('size_bytes')->sum('size_bytes'),
            'last_run_status' => $lastRun?->status,
            'last_run_at' => $lastRun?->finished_at ?? $lastRun?->created_at,
        ]);
    }

    /**
     * ADR-039 decision 2: manual "Backup Now" — queues the same
     * RunDatabaseBackupJob the daily schedule uses, `triggered_by` set
     * to the acting admin's name so history distinguishes manual runs
     * from scheduled ones.
     */
    public function store(Request $request): JsonResponse
    {
        $run = BackupRun::query()->create([
            'status' => 'queued',
            'triggered_by' => $request->user()->name,
        ]);

        RunDatabaseBackupJob::dispatch($run);

        return response()->json($run, 201);
    }

    public function show(BackupRun $backupRun): JsonResponse
    {
        return response()->json($backupRun);
    }

    /**
     * BAK-4: download a completed archive. `path`/`disk` are only set
     * once a run reaches `success`/`failed` with an archive actually on
     * disk — a still-running or archive-less failed run has nothing to
     * download.
     */
    public function download(BackupRun $backupRun): StreamedResponse|JsonResponse
    {
        if ($backupRun->path === null || $backupRun->disk === null) {
            return response()->json(['message' => 'No archive is available for this backup run.'], 404);
        }

        return Storage::disk($backupRun->disk)->download($backupRun->path);
    }

    /**
     * BAK-4: delete a backup file (and its history row). Never touches
     * an in-progress run's archive.
     */
    public function destroy(BackupRun $backupRun): JsonResponse
    {
        if ($backupRun->path !== null && $backupRun->disk !== null) {
            Storage::disk($backupRun->disk)->delete($backupRun->path);
        }

        $backupRun->delete();

        return response()->json(null, 204);
    }
}
