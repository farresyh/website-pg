<?php

namespace App\Services\Notification;

use App\Models\Order;
use App\Models\WhatsappContact;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * ADR-116 decision 5: a direct message to the customer-support number. Three
 * outcomes:
 * - `STOP` opts the number out of receipts and confirms it.
 * - An order number (`PG-…`) plus the word "update" (the "Get updates on
 *   WhatsApp" button's prefilled text) opts the number in and replies.
 * - An order number without it (the Contact Support button's prefilled text)
 *   opts the number in silently, so staff take the conversation. It never
 *   undoes an earlier STOP.
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
            $this->optOut($phone);
            $this->notifications->stopReply($phone, $messageId);

            return;
        }

        if (preg_match('/\bPG-[A-Z0-9]{6,}\b/i', $text, $match) !== 1) {
            return;
        }

        $order = Order::query()->where('order_number', strtoupper($match[0]))->first();
        if ($order === null) {
            return;
        }

        $wantsUpdates = stripos($text, 'update') !== false;
        $this->optIn($phone, $wantsUpdates ? 'updates' : 'support');

        if ($wantsUpdates) {
            $this->notifications->optInReply($order, $phone, $messageId);
        }
    }

    private function optIn(string $phone, string $source): void
    {
        $contact = WhatsappContact::query()->firstOrCreate(
            ['phone' => $phone],
            ['opted_in_at' => now(), 'opt_in_source' => $source],
        );

        // Only an explicit "Get updates" undoes a STOP; a support chat doesn't.
        if ($source === 'updates' && $contact->opted_out_at !== null) {
            $contact->update(['opted_out_at' => null, 'opt_in_source' => 'updates']);
        }
    }

    private function optOut(string $phone): void
    {
        WhatsappContact::query()
            ->firstOrCreate(['phone' => $phone], ['opted_in_at' => now(), 'opt_in_source' => 'support'])
            ->update(['opted_out_at' => now()]);
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
