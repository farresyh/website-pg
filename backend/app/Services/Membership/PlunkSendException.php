<?php

namespace App\Services\Membership;

use RuntimeException;

/** Thrown when Plunk's /v1/send call fails — network error or a non-2xx response. */
final class PlunkSendException extends RuntimeException
{
}
