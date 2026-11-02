<?php

namespace App\Services\Notification;

use App\Jobs\VerifyWhatsAppLidJob;
use App\Models\Order;
use App\Models\WhatsappContact;
use App\Services\OpenWa\OpenWaClient;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-116 decision 5 and its 2026-09-30 and 2026-11-01 addenda: a direct
 * message to the customer-support number.
 * - `STOP` opts the sender out of receipts; `START` opts it back in.
 * - Any message carrying an order number (`PG-…`), whichever button
 *   prefilled it or typed by hand, opts the sender in and gets that order's
 *   status card back, or a "not found" reply for a mistyped number. That
 *   status card row also makes the chat a follower of the order
 *   (CustomerNotificationService::receiptRecipients()).
 * - An order-number message never undoes a STOP; only START does. Otherwise
 *   a customer who said STOP and later chats with support would get receipts
 *   they turned off.
 *
 * The sender is its phone when WhatsApp gives one, else its privacy-id chat
 * (`…@lid`): a hidden number is answered in its own chat, never ignored.
 * Such a chat that sends an order number is checked against the order's
 * checkout phone and tied to it on a match (2026-11-03 addendum).
 * Anything else is a normal support chat and is left alone.
 */
final class CustomerWhatsAppInboundService
{
    public function __construct(private readonly CustomerNotificationService $notifications) {}

    /** @param  array<string, mixed>  $data  OpenWA `message.received` data */
    public function handle(array $data): void
    {
        if (($data['fromMe'] ?? false) === true || ! $this->isDirectMessage($data)) {
            return;
        }

        $text = trim((string) ($data['body'] ?? $data['text'] ?? ''));
        $messageId = (string) ($data['id'] ?? $data['messageId'] ?? '');
        $phone = $this->senderPhone($data);
        $lidChat = $this->senderLidChat($data);

        if ($messageId === '' || $text === '') {
            return;
        }

        if ($phone === null && $lidChat === null) {
            Log::warning('Customer WhatsApp: inbound message with no phone and no privacy-id chat, ignored', ['from' => $data['from'] ?? null]);

            return;
        }

        $to = $phone !== null ? WhatsAppRecipient::phone($phone) : new WhatsAppRecipient(null, $lidChat);

        if (strcasecmp($text, 'STOP') === 0 || strcasecmp($text, 'START') === 0) {
            $stop = strcasecmp($text, 'STOP') === 0;
            $this->rememberSender($phone, $lidChat);
            WhatsappContact::matching($phone, $lidChat)->update(['opted_out_at' => $stop ? now() : null]);
            $stop ? $this->notifications->stopReply($to, $messageId) : $this->notifications->startReply($to, $messageId);

            return;
        }

        if (preg_match('/\bPG-[A-Z0-9]{6,}\b/i', $text, $match) !== 1) {
            return;
        }

        $this->rememberSender($phone, $lidChat);

        // Usually a typo. Order numbers are random per purchase, so saying "not
        // found" gives away nothing guessable (founder's call, ADR-116 addendum).
        $orderNumber = strtoupper($match[0]);
        $order = Order::query()->where('order_number', $orderNumber)->first();
        if ($order === null) {
            $this->notifications->orderNotFound($orderNumber, $to, $messageId);

            return;
        }

        $this->notifications->orderStatusCard($order, $to, $messageId);

        if ($phone === null && $lidChat !== null) {
            $this->queueLidCheck($order, $lidChat);
        }
    }

    /**
     * ADR-116 2026-11-03 addendum decision 1: called by VerifyWhatsAppLidJob.
     * The checkout phone is the truth: only OpenWA saying that phone's `@lid`
     * is this chat ties them. Throws when OpenWA does not answer (the job retries).
     */
    public function verifyLid(int $orderId, string $lidChat): void
    {
        $order = Order::query()->find($orderId);
        $checkout = $order !== null && $this->notifications->inScope($order) ? PhoneNumber::normalize($order->customer_phone) : null;

        if ($checkout === null || OpenWaClient::customerSupport()->lidForPhone($checkout) !== $lidChat) {
            return;
        }

        $this->tie($checkout, $lidChat);
    }

    private function queueLidCheck(Order $order, string $lidChat): void
    {
        $checkout = PhoneNumber::normalize($order->customer_phone);

        if ($checkout === null || ! $this->notifications->inScope($order)
            || WhatsappContact::query()->where('phone', $checkout)->where('chat_id', $lidChat)->exists()) {
            return;
        }

        VerifyWhatsAppLidJob::dispatch($order->id, $lidChat);
    }

    /**
     * Decisions 2–4: one row holds both. The phone's row is kept; any other
     * row for this phone or chat is folded into it and deleted first (both
     * columns are unique). STOP on either wins; the earlier opt-in is kept.
     * A phone tied to an older chat takes this one.
     */
    private function tie(string $phone, string $lidChat): void
    {
        DB::transaction(function () use ($phone, $lidChat) {
            $rows = WhatsappContact::matching($phone, $lidChat)->lockForUpdate()->get();

            if ($rows->isEmpty()) {
                WhatsappContact::query()->create(['phone' => $phone, 'chat_id' => $lidChat, 'opted_in_at' => now(), 'opt_in_source' => 'lid_match']);

                return;
            }

            $keep = $rows->firstWhere('phone', $phone) ?? $rows->first();
            $others = $rows->reject(fn (WhatsappContact $row) => $row->is($keep));

            $keep->opted_out_at ??= $others->pluck('opted_out_at')->filter()->min();
            $keep->opted_in_at = $rows->pluck('opted_in_at')->min();
            $others->each->delete();

            $keep->phone = $phone;
            $keep->chat_id = $lidChat;
            $keep->save();
        });

        Log::info('Customer WhatsApp: privacy-id chat tied to the checkout phone', ['chat_id' => $lidChat]);
    }

    /**
     * One row per sender. When a message carries both a phone and a privacy
     * id, both are stored on the one row that matches. Two rows that already
     * exist (the same person seen first one way, then the other) are left
     * apart: WhatsappContact reads STOP on either as STOP.
     */
    private function rememberSender(?string $phone, ?string $lidChat): void
    {
        $rows = WhatsappContact::matching($phone, $lidChat)->get();

        if ($rows->isEmpty()) {
            try {
                WhatsappContact::query()->create(['phone' => $phone, 'chat_id' => $lidChat, 'opted_in_at' => now(), 'opt_in_source' => 'message']);
            } catch (UniqueConstraintViolationException) {
                // A concurrent webhook for the same sender created it first.
            }

            return;
        }

        if ($rows->count() === 1) {
            $row = $rows->first();
            $row->phone ??= $phone;
            $row->chat_id ??= $lidChat;
            $row->save();
        }
    }

    /** @param  array<string, mixed>  $data */
    private function isDirectMessage(array $data): bool
    {
        if (isset($data['kind']) && is_string($data['kind'])) {
            return $data['kind'] === 'individual';
        }

        return ! (bool) ($data['isGroup'] ?? false);
    }

    /**
     * OpenWA gives `from` as `<digits>@c.us`. For a WhatsApp privacy id (`@lid`)
     * it adds a best-effort `senderPhone` instead, often null for a stranger.
     *
     * @param  array<string, mixed>  $data
     */
    private function senderPhone(array $data): ?string
    {
        $candidate = $data['senderPhone'] ?? null;
        $from = (string) ($data['from'] ?? $data['chatId'] ?? '');

        if (! is_string($candidate) && str_ends_with($from, '@c.us')) {
            $candidate = substr($from, 0, -5);
        }

        return PhoneNumber::normalize(is_string($candidate) ? $candidate : null);
    }

    /** @param  array<string, mixed>  $data */
    private function senderLidChat(array $data): ?string
    {
        $from = (string) ($data['from'] ?? $data['chatId'] ?? '');

        return preg_match('/^\d+@lid$/', $from) === 1 ? $from : null;
    }
}
