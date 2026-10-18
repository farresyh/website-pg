<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LinkWhatsAppGroupRequest;
use App\Http\Requests\Admin\UpdateResellerStatusRequest;
use App\Http\Requests\Admin\UpdateSupplierSlaRequest;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\WhatsAppGroup;
use App\Services\Order\DeliveryStatus;
use App\Services\Supplier\Manual\SupplierGroupNotifier;
use App\Services\WhatsApp\WhatsAppGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * ADR-125 decision 60: Admin → Partners → Suppliers, super_admin only. A
 * manual supplier as a partner: its WhatsApp group, reply SLA and active
 * switch. Creating one and its funding mode stay in Middleware → Suppliers;
 * API suppliers never appear here.
 */
class PartnerSupplierController extends Controller
{
    public function __construct(
        private readonly WhatsAppGroupService $groups,
        private readonly SupplierGroupNotifier $notifier,
    ) {}

    public function index(): JsonResponse
    {
        $suppliers = Supplier::query()->where('driver', Supplier::DRIVER_MANUAL)->orderBy('name')->get();

        $pending = Order::query()
            ->whereIn('supplier_id', $suppliers->pluck('id'))
            ->where('delivery_status', DeliveryStatus::Pending->value)
            ->where('is_test', false)
            ->selectRaw('supplier_id, count(*) as aggregate')
            ->groupBy('supplier_id')
            ->pluck('aggregate', 'supplier_id');

        return response()->json($suppliers->map(fn (Supplier $supplier) => $this->publicSupplier($supplier, (int) ($pending[$supplier->id] ?? 0)))->values());
    }

    public function linkGroup(LinkWhatsAppGroupRequest $request, Supplier $supplier): JsonResponse
    {
        $group = $this->groups->linkSupplier($supplier, $request->validated('whatsapp_group_id'));

        Log::info('Supplier WhatsApp group linked', ['supplier_id' => $supplier->id, 'whatsapp_group_id' => $group->whatsapp_group_id]);
        $this->notifier->repostWaiting($supplier);

        return response()->json($this->publicSupplier($supplier->fresh()), 201);
    }

    public function updateGroupStatus(UpdateResellerStatusRequest $request, Supplier $supplier, WhatsAppGroup $group): JsonResponse
    {
        abort_unless($group->isOwnedBy(WhatsAppGroup::OWNER_SUPPLIER, $supplier->id), 404);

        if ($request->validated('is_active')) {
            $this->groups->reactivate($group);
            $this->notifier->repostWaiting($supplier);
        } else {
            $this->groups->unlink($group);
        }

        Log::info('Supplier WhatsApp group status changed', [
            'supplier_id' => $supplier->id,
            'whatsapp_group_id' => $group->whatsapp_group_id,
            'is_active' => $request->validated('is_active'),
        ]);

        return response()->json($this->publicSupplier($supplier->fresh()));
    }

    public function updateSla(UpdateSupplierSlaRequest $request, Supplier $supplier): JsonResponse
    {
        abort_if($supplier->isApiBacked(), 404);

        $supplier->update(['api_config' => array_merge($supplier->api_config ?? [], [
            'reply_sla_minutes' => $request->validated('reply_sla_minutes'),
        ])]);

        return response()->json($this->publicSupplier($supplier->fresh()));
    }

    public function updateStatus(UpdateResellerStatusRequest $request, Supplier $supplier): JsonResponse
    {
        abort_if($supplier->isApiBacked(), 404);

        if ($request->validated('is_active')) {
            $this->groups->assertSupplierMayBeActive($supplier);
        }

        $supplier->update(['is_active' => $request->validated('is_active')]);

        return response()->json($this->publicSupplier($supplier->fresh()));
    }

    /** @return array<string, mixed> */
    private function publicSupplier(Supplier $supplier, ?int $pendingOrders = null): array
    {
        $groups = WhatsAppGroup::query()
            ->where('owner_type', WhatsAppGroup::OWNER_SUPPLIER)
            ->where('owner_id', $supplier->id)
            ->orderByDesc('is_active')
            ->latest()
            ->get();

        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'funding_mode' => $supplier->funding_mode,
            'is_active' => $supplier->is_active,
            'reply_sla_minutes' => $supplier->pendingPolicy()['stale_minutes'],
            'pending_orders' => $pendingOrders,
            'whatsapp_groups' => $groups->map(fn (WhatsAppGroup $group) => [
                'id' => $group->id,
                'whatsapp_group_id' => $group->whatsapp_group_id,
                'is_active' => $group->is_active,
                'created_at' => $group->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
