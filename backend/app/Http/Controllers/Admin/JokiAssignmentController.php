<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignJokiOrderRequest;
use App\Models\Order;
use App\Services\Joki\JokiAssignmentService;
use Illuminate\Http\JsonResponse;

/** ADR-126 decision 16: Assign, from the Joki page or Order Detail (PR 4 builds the screens). */
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
}
