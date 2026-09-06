<?php

namespace App\Services\Affiliate\Domain;

use RuntimeException;
use Throwable;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum, section C): a failure
 * from the frontend hosting provider (Vercel) while attaching, checking,
 * or removing a custom domain.
 *
 * The `message` is ALWAYS already sanitised for an affiliate to read —
 * "this domain is already registered elsewhere — contact support", never
 * the raw provider string. The provider's own error is written to the
 * log by `VercelDomainProvider`, not carried here. The portal surfaces
 * `getMessage()` verbatim.
 *
 * `$alreadyInUse` is the one case the portal treats specially (the
 * affiliate genuinely cannot self-serve past it); every other failure is
 * a generic "try again / contact support".
 */
class DomainProviderException extends RuntimeException
{
    public function __construct(
        string $sanitisedMessage,
        public readonly bool $alreadyInUse = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($sanitisedMessage, 0, $previous);
    }

    public static function alreadyInUse(): self
    {
        return new self(
            'This domain is already registered on another site. Contact support to have it released.',
            alreadyInUse: true,
        );
    }

    public static function generic(?Throwable $previous = null): self
    {
        return new self(
            'The domain could not be set up right now. Check the DNS records and try again, or contact support.',
            previous: $previous,
        );
    }
}
