<?php

namespace App\Http\Controllers\ResellerPortal;

use App\Models\CustomerNotification;
use App\Services\Notification\WhatsAppOutbox;
use App\Services\OpenWa\OpenWaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-075 2026-11-14 addendum, decision 4: whether the WhatsApp bot is
 * degraded, for the portal's banner. Only a boolean leaves the backend. The
 * bot session not `ready`, or a bot reply waiting over 2 minutes past its
 * due time, is degraded; a customer-support problem is not the bot's. Cached
 * 30 s, so a portal asking every minute never adds load on OpenWA.
 */
class BotStatusController extends Controller
{
    private const LATE_REPLY_SECONDS = 120;

    public function show(OpenWaClient $bot): JsonResponse
    {
        $degraded = Cache::remember('reseller-portal:bot-degraded', 30, function () use ($bot) {
            if ($bot->sessionStatus()['status'] !== 'ready') {
                return true;
            }

            return CustomerNotification::query()
                ->where('status', CustomerNotification::STATUS_QUEUED)
                ->whereIn('event', WhatsAppOutbox::REPLY_EVENTS)
                ->where('due_at', '<', now()->subSeconds(self::LATE_REPLY_SECONDS))
                ->exists();
        });

        return response()->json(['degraded' => $degraded]);
    }
}
