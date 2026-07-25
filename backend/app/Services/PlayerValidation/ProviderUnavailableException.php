<?php

namespace App\Services\PlayerValidation;

use RuntimeException;

/**
 * Thrown by an individual provider (network error, unexpected HTTP
 * status, unparseable body/token) — signals "try the next provider in
 * the chain", never "this ID is invalid". These providers are
 * unofficial/reverse-engineered endpoints with no SLA (see docs/adr.md
 * ADR-005 addendum), so unreachability is an expected, routine case,
 * not exceptional in the "something is broken" sense.
 */
final class ProviderUnavailableException extends RuntimeException {}
