<?php

namespace App\Services\Payment;

/**
 * Xendit's Payment Request `customer` object (confirmed against real
 * docs, 2026-07-25 — docs.xendit.co/apidocs/create-payment-request):
 * `type` is fixed to INDIVIDUAL (guest checkout has no business
 * customers), `individual_detail.given_names` is the one field
 * Xendit actually requires — discovered live via the Payment Methods
 * "Test" action returning "Only one of 'customer' or 'customer_id'
 * should be present" for a request that sent neither, which turned
 * out to mean "FPX needs exactly one, and you sent zero."
 * `referenceId` is the customer-level reference Xendit's schema also
 * asks for (separate from PaymentRequest::referenceId, which
 * identifies the *transaction*) — reused from the same Order's
 * order_number since guest checkout has no persistent customer
 * identity to key it against instead.
 */
final class PaymentCustomer
{
    public function __construct(
        public readonly string $referenceId,
        public readonly string $givenNames,
        public readonly ?string $email = null,
        public readonly ?string $mobileNumber = null,
    ) {
    }
}
