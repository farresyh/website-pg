<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Requests\Middleware\StartBackupRunRequest;
use App\Models\BackupRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
        $source = $request->validate(['source' => ['sometimes', 'string', Rule::in(BackupRun::SOURCES)]])['source'] ?? null;

        return response()->json(
            BackupRun::query()
                ->when($source, fn ($query) => $query->where('source', $source))
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->paginate($perPage)
                ->withQueryString(),
        );
    }

    /**
     * BAK-1: total count, total size, and the latest run of each source
     * (2026-10-22 addendum), so a failing OpenWA backup never hides
     * behind a newer successful database one.
     */
    public function stats(): JsonResponse
    {
        $lastRuns = [];
        foreach (BackupRun::SOURCES as $source) {
            $run = BackupRun::query()->where('source', $source)->latest('id')->first();
            $lastRuns[$source] = $run === null ? null : ['status' => $run->status, 'at' => $run->finished_at ?? $run->created_at];
        }

        return response()->json([
            'total_count' => BackupRun::query()->count(),
            'total_size_bytes' => (int) BackupRun::query()->whereNotNull('size_bytes')->sum('size_bytes'),
            'last_runs' => $lastRuns,
        ]);
    }

    /**
     * ADR-039 decision 2: manual "Backup Now" — queues the same job the
     * daily schedule uses for that source (`BackupRun::queue()`),
     * `triggered_by` set to the acting admin's name so history
     * distinguishes manual runs from scheduled ones. Before an OpenWA
     * upgrade, run it for `openwa` (2026-10-22 addendum).
     */
    public function store(StartBackupRunRequest $request): JsonResponse
    {
        $run = BackupRun::queue($request->validated('source', BackupRun::SOURCE_DATABASE), $request->user()->name);

        return response()->json($run->refresh(), 201);
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
