<?php

namespace App\Services\Notification;

/**
 * ADR-116 2026-11-01 addendum: who a customer message goes to. A normalised
 * phone when we have one (sent to `<phone>@c.us`), else the privacy-id chat
 * (`…@lid`) the customer wrote from. `chatId` is set only when there is no
 * phone, so a row's `chat_id ?? phone@c.us` is always the right address.
 */
final readonly class WhatsAppRecipient
{
    public function __construct(
        public ?string $phone,
        public ?string $chatId = null,
    ) {}

    public static function phone(?string $phone): self
    {
        return new self($phone);
    }

    /** The one value that tells two recipients apart, for a per-recipient dedupe key. */
    public function key(): ?string
    {
        return $this->phone ?? $this->chatId;
    }
}
