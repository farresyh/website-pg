<?php

namespace App\Services\PlayerValidation;

use RuntimeException;

/**
 * Thrown when a `games.player_validator` value has no corresponding
 * binding — mirrors UnsupportedPaymentGatewayException's role for
 * PaymentGatewayFactory. Fails loud rather than silently skipping
 * validation, since that would defeat the wrong-region safety net a
 * game was explicitly configured to have.
 */
final class UnsupportedPlayerValidatorException extends RuntimeException {}
