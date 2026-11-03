<?php

namespace App\Services\CsAssistant;

/**
 * ADR-136 decisions 6 and 7, in code rather than in the prompt: what an
 * order's state means for the reply. A forced handover is applied by
 * CsAnswerer whatever the model says, so a failed or late order always
 * reaches a person.
 */
final readonly class CsOrderSituation
{
    public const FAILED = 'order_failed';

    public const LATE = 'late';

    private function __construct(
        public string $situation,
        public ?string $forcedHandover,
        public string $guidance,
    ) {}

    /**
     * @param  array{payment_status: string, delivery_status: string, minutes_since_paid?: int|null, expected_minutes?: int|null, package?: string|null, game?: string|null}  $facts
     */
    public static function of(array $facts): self
    {
        $reassure = (int) config('services.cs_assistant.reassure_minutes', 15);
        $limit = (int) ($facts['expected_minutes'] ?? config('services.cs_assistant.handover_minutes', 30));
        $minutes = (int) ($facts['minutes_since_paid'] ?? 0);

        if ($facts['payment_status'] !== 'paid') {
            return new self('payment_not_completed', null,
                'The payment for this order did not go through, so nothing was charged and nothing is late. Explain that kindly and tell the customer to place the order again; do not hand over.');
        }

        return match ($facts['delivery_status']) {
            'failed', 'partially_delivered' => new self('failed', self::FAILED,
                'This order failed. Tell the customer the team has been told and will reply in this chat.'),
            'delivered' => new self('delivered', null,
                'This order was delivered. If the customer says it did not arrive, first check the approved knowledge for an explanation (for example a first-top-up bonus that applies once per account) and answer with it; hand over with reason not_received only if no entry explains it or the customer still says it is missing.'),
            default => match (true) {
                $minutes > $limit => new self('late', self::LATE,
                    'This paid order is past its expected delivery time. Tell the customer the team has been told and will reply in this chat.'),
                $minutes >= $reassure => new self('delayed', null,
                    "This order was paid {$minutes} minutes ago and is still being processed. Acknowledge the delay, say it is being processed, and ask the customer to message again if it has not arrived within the next 15 minutes. Do not hand over."),
                default => new self('processing', null,
                    "This order was paid {$minutes} minutes ago and is being processed; most orders arrive within a few minutes. Reassure the customer. Do not hand over."),
            },
        };
    }
}
