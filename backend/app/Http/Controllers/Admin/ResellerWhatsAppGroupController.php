<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LinkWhatsAppGroupRequest;
use App\Http\Requests\Admin\UpdateResellerStatusRequest;
use App\Models\Reseller;
use App\Models\WhatsAppGroup;
use App\Services\WhatsApp\WhatsAppGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * PR-F build addendum decision 3: admin's group-linking UX, scoped to one
 * Reseller. The platform-wide pending list is WhatsAppPendingLinkController
 * (shared with suppliers, ADR-125 decision 60). Same super_admin tier as
 * the rest of `/admin/resellers*`.
 */
class ResellerWhatsAppGroupController extends Controller
{
    public function __construct(private readonly WhatsAppGroupService $groups) {}

    public function index(Reseller $reseller): JsonResponse
    {
        return response()->json(
            $reseller->whatsAppGroups()->orderByDesc('created_at')->get()->map(fn (WhatsAppGroup $group) => self::publicGroup($group))
        );
    }

    public function store(LinkWhatsAppGroupRequest $request, Reseller $reseller): JsonResponse
    {
        $group = $this->groups->linkReseller($reseller, $request->validated('whatsapp_group_id'));

        Log::info('Reseller WhatsApp group linked', ['reseller_id' => $reseller->id, 'whatsapp_group_id' => $group->whatsapp_group_id]);

        return response()->json(self::publicGroup($group), 201);
    }

    public function updateStatus(UpdateResellerStatusRequest $request, Reseller $reseller, WhatsAppGroup $group): JsonResponse
    {
        abort_unless($group->isOwnedBy(WhatsAppGroup::OWNER_RESELLER, $reseller->id), 404);

        $isActive = $request->validated('is_active');
        $isActive ? $this->groups->reactivate($group) : $this->groups->unlink($group);

        Log::info('Reseller WhatsApp group status changed', [
            'reseller_id' => $reseller->id,
            'whatsapp_group_id' => $group->whatsapp_group_id,
            'is_active' => $isActive,
        ]);

        return response()->json(self::publicGroup($group->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function publicGroup(WhatsAppGroup $group): array
    {
        return [
            'id' => $group->id,
            'whatsapp_group_id' => $group->whatsapp_group_id,
            'is_active' => $group->is_active,
            'created_at' => $group->created_at?->toIso8601String(),
        ];
    }
}
