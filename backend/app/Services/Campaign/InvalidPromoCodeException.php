<?php

namespace App\Services\Campaign;

use RuntimeException;

final class InvalidPromoCodeException extends RuntimeException
{
    public function __construct(public readonly PromoRejection $reason)
    {
        parent::__construct($reason->message());
    }
}
