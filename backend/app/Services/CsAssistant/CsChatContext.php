<?php

namespace App\Services\CsAssistant;

use App\Models\CsAssistantSettings;
use App\Models\CsMessage;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Services\Order\PaymentStatus;
use Illuminate\Support\Collection;

/**
 * What Laravel knows about a CS chat, looked up from the database and never
 * from the message text (decisions 9 and 13): the brand, and the facts of the
 * order the customer named. Shared by the nightly suggester and Shadow.
 */
final class CsChatContext
{
    /**
     * Decision 9: the order's own brand, else that of our latest send to the
     * chat before the question, else unknown (null).
     *
     * @param  list<string>  $orderNumbers
     * @param  Collection<int, CsMessage>  $chat  oldest first
     */
    public static function brand(array $orderNumbers, Collection $chat, CsMessage $first): ?int
    {
        if ($orderNumbers !== []) {
            $id = Order::query()->whereIn('order_number', $orderNumbers)->value('affiliate_id');
            if ($id !== null) {
                return $id;
            }
        }

        $send = $chat->filter(fn (CsMessage $m) => $m->customer_notification_id !== null && $m->sent_at->lte($first->sent_at))->last()
            ?? $chat->firstWhere('customer_notification_id', '!=', null);

        if ($send === null) {
            return null;
        }

        return Order::query()
            ->whereKey(CustomerNotification::query()->whereKey($send->customer_notification_id)->value('order_id'))
            ->value('affiliate_id');
    }

    /**
     * The facts CsOrderSituation reads, for the newest real order the
     * customer named, or null when none of the numbers is an order.
     *
     * @param  list<string>  $orderNumbers
     * @return array{payment_status: string, delivery_status: string, minutes_since_paid: ?int, expected_minutes: ?int, package: ?string, game: ?string}|null
     */
    public static function orderFacts(array $orderNumbers): ?array
    {
        if ($orderNumbers === []) {
            return null;
        }

        $order = Order::query()
            ->where('is_test', false)
            ->whereIn('order_number', $orderNumbers)
            ->with(['game:id,name,delivery_mode', 'package:id,name'])
            ->latest('id')
            ->first();

        if ($order === null) {
            return null;
        }

        $paid = $order->payment_status === PaymentStatus::Paid;

        return [
            'payment_status' => $order->payment_status->value,
            'delivery_status' => $order->delivery_status->value,
            'minutes_since_paid' => $paid && $order->paid_at !== null ? (int) $order->paid_at->diffInMinutes(now()) : null,
            // Decision 7: a manual game has its own, longer limit (a panel setting; games carry no per-game time).
            'expected_minutes' => $order->game?->delivery_mode === 'manual' ? CsAssistantSettings::current()->manual_handover_minutes : null,
            'package' => $order->package?->name,
            'game' => $order->game?->name,
        ];
    }
}
