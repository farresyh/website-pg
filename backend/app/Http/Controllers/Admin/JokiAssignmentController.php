<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignJokiOrderRequest;
use App\Http\Requests\Admin\UpdateJokiProgressRequest;
use App\Models\Order;
use App\Services\Joki\JokiAssignmentService;
use App\Services\Joki\JokiProgressService;
use Illuminate\Http\JsonResponse;

/**
 * ADR-126 decisions 10 and 16: Assign, and a progress update, from the
 * Joki page or Order Detail (PR 4 builds the screens).
 */
class JokiAssignmentController extends Controller
{
    public function store(AssignJokiOrderRequest $request, Order $order, JokiAssignmentService $assignments): JsonResponse
    {
        $assigned = $assignments->assign($order, (int) $request->validated('supplier_id'), $request->user()->name);

        return response()->json([
            'order_number' => $assigned->order_number,
            'supplier_id' => $assigned->supplier_id,
            'delivery_status' => $assigned->delivery_status->value,
        ]);
    }

    public function progress(UpdateJokiProgressRequest $request, Order $order, JokiProgressService $progress): JsonResponse
    {
        $joki = $progress->record($order, $request->validated(), $request->user()->name, 'admin');

        return response()->json(['current_rank' => $joki->current_rank, 'current_rank_at' => $joki->current_rank_at?->toISOString()]);
    }
}
