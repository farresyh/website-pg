<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blacklist\CreateBlacklistEntryRequest;
use App\Models\BlacklistEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ADR-007 / FRAUD-1..3 admin screen. Entries are never hard-deleted
 * (see the migration's doc comment) - "remove" is deactivate().
 */
class BlacklistController extends Controller
{
    public function index(): JsonResponse
    {
        $entries = BlacklistEntry::query()
            ->withCount('hits')
            ->with('creator:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'stats' => [
                'active' => $entries->where('is_active', true)->count(),
                'total' => $entries->count(),
            ],
            'entries' => $entries,
        ]);
    }

    public function store(CreateBlacklistEntryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $exists = BlacklistEntry::query()
            ->where('type', $data['type'])
            ->where('value', $data['value'])
            ->where('is_active', true)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'value' => ['An active blacklist entry already exists for this value.'],
            ]);
        }

        $entry = BlacklistEntry::query()->create([
            'type' => $data['type'],
            'value' => $data['value'],
            'reason' => $data['reason'],
            'created_by' => $request->user()->id,
            'is_active' => true,
        ]);

        return response()->json($entry->load('creator:id,name'), 201);
    }

    /**
     * FRAUD-3: "view history of orders blocked by a given entry" -
     * a blocked attempt never creates an Order, so this is the hits
     * relation, not an Order query.
     */
    public function show(BlacklistEntry $blacklistEntry): JsonResponse
    {
        return response()->json(
            $blacklistEntry->load(['creator:id,name', 'hits' => fn ($query) => $query->orderBy('created_at', 'desc')]),
        );
    }

    public function deactivate(Request $request, BlacklistEntry $blacklistEntry): JsonResponse
    {
        if (! $blacklistEntry->is_active) {
            throw ValidationException::withMessages([
                'status' => ['This entry is already inactive.'],
            ]);
        }

        $blacklistEntry->update(['is_active' => false]);

        return response()->json($blacklistEntry);
    }
}
