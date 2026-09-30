<?php

namespace App\Services\Notification;

use App\Models\Order;
use App\Models\WhatsappContact;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * ADR-116 decision 5 and its 2026-09-30 addendum: a direct message to the
 * customer-support number.
 * - `STOP` opts the number out of receipts; `START` opts it back in.
 * - Any message carrying a valid order number (`PG-…`), whichever button
 *   prefilled it or typed by hand, opts the number in and gets that order's
 *   status card back. Messaging with an order number is the opt-in, since
 *   what we need to know is that this number wrote to us first.
 * - An order-number message never undoes a STOP; only START does. Otherwise
 *   a customer who said STOP and later chats with support would get receipts
 *   they turned off.
 *
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

        if ($phone === null || $messageId === '' || $text === '') {
            return;
        }

        if (strcasecmp($text, 'STOP') === 0) {
            $this->contact($phone)->update(['opted_out_at' => now()]);
            $this->notifications->stopReply($phone, $messageId);

            return;
        }

        if (strcasecmp($text, 'START') === 0) {
            $this->contact($phone)->update(['opted_out_at' => null]);
            $this->notifications->startReply($phone, $messageId);

            return;
        }

        if (preg_match('/\bPG-[A-Z0-9]{6,}\b/i', $text, $match) !== 1) {
            return;
        }

        // An unknown order number stays silent, so the bot can't be used to probe order numbers.
        $order = Order::query()->where('order_number', strtoupper($match[0]))->first();
        if ($order === null) {
            return;
        }

        $this->contact($phone);
        $this->notifications->orderStatusCard($order, $phone, $messageId);
    }

    private function contact(string $phone): WhatsappContact
    {
        return WhatsappContact::query()->firstOrCreate(
            ['phone' => $phone],
            ['opted_in_at' => now(), 'opt_in_source' => 'message'],
        );
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
     * it adds a best-effort `senderPhone` instead. No usable phone means the
     * message is ignored: there is no number to opt in or reply to.
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

        $phone = PhoneNumber::normalize(is_string($candidate) ? $candidate : null);

        if ($phone === null) {
            Log::info('Customer WhatsApp: inbound message with no usable phone, ignored');
        }

        return $phone;
    }
}
