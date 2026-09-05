<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LinkResellerWhatsAppGroupRequest;
use App\Http\Requests\Admin\UpdateResellerStatusRequest;
use App\Models\Reseller;
use App\Models\ResellerWhatsAppGroup;
use App\Models\ResellerWhatsAppPendingLink;
use App\Services\Reseller\Bot\ResellerWhatsAppGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * PR-F build addendum decision 3: admin's group-linking UX. `pending()`
 * is platform-wide (a captured group isn't yet attributed to any
 * Reseller — that's the whole point), the rest are scoped to one
 * Reseller. Same super_admin tier as the rest of `/admin/resellers*`.
 */
class ResellerWhatsAppGroupController extends Controller
{
    public function __construct(private readonly ResellerWhatsAppGroupService $groups) {}

    public function pending(): JsonResponse
    {
        return response()->json(
            ResellerWhatsAppPendingLink::query()
                ->orderByDesc('last_message_at')
                ->get()
                ->map(fn (ResellerWhatsAppPendingLink $link) => [
                    'whatsapp_group_id' => $link->whatsapp_group_id,
                    'last_message_preview' => $link->last_message_preview,
                    'last_message_at' => $link->last_message_at?->toIso8601String(),
                ])
        );
    }

    public function index(Reseller $reseller): JsonResponse
    {
        return response()->json(
            $reseller->whatsAppGroups()->orderByDesc('created_at')->get()->map(fn (ResellerWhatsAppGroup $group) => self::publicGroup($group))
        );
    }

    public function store(LinkResellerWhatsAppGroupRequest $request, Reseller $reseller): JsonResponse
    {
        $group = $this->groups->link($reseller, $request->validated('whatsapp_group_id'));

        Log::info('Reseller WhatsApp group linked', ['reseller_id' => $reseller->id, 'whatsapp_group_id' => $group->whatsapp_group_id]);

        return response()->json(self::publicGroup($group), 201);
    }

    public function updateStatus(UpdateResellerStatusRequest $request, Reseller $reseller, ResellerWhatsAppGroup $group): JsonResponse
    {
        abort_unless($group->reseller_id === $reseller->id, 404);

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
    private static function publicGroup(ResellerWhatsAppGroup $group): array
    {
        return [
            'id' => $group->id,
            'whatsapp_group_id' => $group->whatsapp_group_id,
            'is_active' => $group->is_active,
            'created_at' => $group->created_at?->toIso8601String(),
        ];
    }
}
