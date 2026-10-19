<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppPendingLink;
use Illuminate\Http\JsonResponse;

/**
 * Bot-session groups nobody has linked yet, platform-wide: a captured
 * group isn't attributed to anyone, so the reseller and supplier link
 * screens both pick from this one list (ADR-125 decision 60).
 */
class WhatsAppPendingLinkController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(
            WhatsAppPendingLink::query()
                ->orderByDesc('last_message_at')
                ->get()
                ->map(fn (WhatsAppPendingLink $link) => [
                    'whatsapp_group_id' => $link->whatsapp_group_id,
                    'last_message_preview' => $link->last_message_preview,
                    'last_message_at' => $link->last_message_at?->toIso8601String(),
                ])
        );
    }
}
