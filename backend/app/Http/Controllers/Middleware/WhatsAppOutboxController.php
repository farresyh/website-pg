<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Support\ContactMask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ADR-116 2026-11-14 addendum, decision 12: a read-only view of the
 * WhatsApp outbox, to see what is stuck, failed or of unknown outcome.
 * Super Admin only, like every other screen under `/middleware`. The
 * message text is never returned (it holds receipts and order details);
 * a recipient's number is masked, and a group shows as a group.
 */
class WhatsAppOutboxController extends Controller
{
    private const STATUSES = [
        CustomerNotification::STATUS_QUEUED,
        CustomerNotification::STATUS_SENT,
        CustomerNotification::STATUS_FAILED,
        CustomerNotification::STATUS_SKIPPED,
        CustomerNotification::STATUS_UNKNOWN,
        CustomerNotification::STATUS_EXPIRED,
        CustomerNotification::STATUS_SUPERSEDED,
    ];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'session' => ['nullable', Rule::in([CustomerNotification::SESSION_CUSTOMER_SUPPORT, CustomerNotification::SESSION_BOT])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = CustomerNotification::query()
            ->with('order:id,order_number')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['session'] ?? null, fn ($q, $session) => $q->where('session', $session))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $page->getCollection()->transform(fn (CustomerNotification $n) => [
            'id' => $n->id,
            'session' => $n->session,
            'event' => $n->event,
            'status' => $n->status,
            'recipient' => $n->phone !== null && $n->phone !== '' ? ContactMask::phone($n->phone) : (str_ends_with((string) $n->chat_id, '@g.us') ? 'group' : 'chat'),
            'order_number' => $n->order?->order_number,
            'attempts' => $n->attempts,
            'error' => $n->error,
            'created_at' => $n->created_at?->toIso8601String(),
            'due_at' => $n->due_at?->toIso8601String(),
            'sent_at' => $n->sent_at?->toIso8601String(),
        ]);

        return response()->json($page);
    }
}
