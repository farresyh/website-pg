<?php

namespace App\Exceptions\ResellerApi;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * ADR-084 PR-1 decision 3: the one exception type the Reseller API
 * channel throws for every 4xx. It carries a **stable machine-readable
 * `error` code** (never an English `message` an integrator would have to
 * parse) plus a meaningful HTTP status; `bootstrap/app.php`'s render hook
 * turns it — and the framework exceptions that occur on the
 * `api/reseller/*` path — into the uniform envelope:
 *
 *   { "error": "<CODE>", "message": "<human text>", "details"?: {...} }
 *
 * Named constructors keep the whole code catalogue in one file (it is the
 * source the docs' Errors page is written from — decision 8).
 */
final class ResellerApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $statusCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function missingApiKey(): self
    {
        return new self('MISSING_API_KEY', 401, 'An API key is required. Send it as a bearer token.');
    }

    public static function invalidApiKey(): self
    {
        return new self('INVALID_API_KEY', 401, 'The API key is invalid or has been revoked.');
    }

    public static function resellerInactive(): self
    {
        return new self('RESELLER_INACTIVE', 403, 'This reseller account is deactivated.');
    }

    public static function ipNotAllowed(): self
    {
        return new self('IP_NOT_ALLOWED', 403, 'This API key is not permitted to be used from your IP address.');
    }

    public static function orderNotFound(): self
    {
        return new self('ORDER_NOT_FOUND', 404, 'No order was found for that order number.');
    }

    public static function idempotencyKeyConflict(): self
    {
        return new self('IDEMPOTENCY_KEY_CONFLICT', 409, 'This idempotency_key was already used for an order with a different payload. Use a fresh key per logical order.');
    }

    public static function unknownProductCode(): self
    {
        return new self('UNKNOWN_PRODUCT_CODE', 422, 'Unknown or currently unavailable product_code.');
    }

    public static function noTierAssigned(): self
    {
        return new self('NO_TIER_ASSIGNED', 422, 'This reseller account has no wallet tier assigned. Contact us to set one.');
    }

    public static function insufficientBalance(): self
    {
        return new self('INSUFFICIENT_BALANCE', 422, 'Wallet balance is insufficient for this order.');
    }

    /**
     * @param  array<string, array<int, string>>  $details
     */
    public static function validationFailed(array $details): self
    {
        return new self('VALIDATION_FAILED', 422, 'The request payload failed validation.', $details);
    }

    public static function rateLimited(): self
    {
        return new self('RATE_LIMITED', 429, 'Too many requests. Retry after the period given in the Retry-After header.');
    }

    public static function notFound(): self
    {
        return new self('NOT_FOUND', 404, 'This endpoint does not exist.');
    }

    public static function methodNotAllowed(): self
    {
        return new self('METHOD_NOT_ALLOWED', 405, 'This HTTP method is not allowed on this endpoint.');
    }

    public function toResponse(): JsonResponse
    {
        $body = ['error' => $this->errorCode, 'message' => $this->getMessage()];

        if ($this->details !== []) {
            $body['details'] = $this->details;
        }

        return response()->json($body, $this->statusCode);
    }
}
