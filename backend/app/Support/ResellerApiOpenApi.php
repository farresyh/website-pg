<?php

namespace App\Support;

use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * The one place the public Reseller API spec (docs.pekangame.space) is adjusted
 * after Scramble infers it from the controllers. Registered with
 * `Scramble::extendOpenApi()` in AppServiceProvider.
 *
 * - The bearer security scheme (ADR-074 decision 1): Scramble cannot detect the
 *   Reseller API's own guard.
 * - Internal wording is blanked from component descriptions (ADR-084 PR-4):
 *   Scramble lifts the class docblock of every referenced enum/FormRequest, and
 *   those shared classes carry internal cross-references.
 * - Response headers. Each one here is pinned by OrderWireShapeTest (rate-limit
 *   and replay headers) or RejectionLogTest (`Retry-After` on a 429).
 */
final class ResellerApiOpenApi
{
    private const INTERNAL_WORDING = '/\b(ADR-\d|ORD-\d|PR-[A-Z]?\d|Xendit|Supplier API|payment gateway|Create\w+Request)\b/';

    public function __invoke(OpenApi $openApi): void
    {
        $openApi->secure(SecurityScheme::http('bearer')->as('Reseller API key'));

        foreach ($openApi->components->schemas as $schema) {
            $description = $schema->type->description ?? '';

            if ($description !== '' && preg_match(self::INTERNAL_WORDING, $description)) {
                $schema->type->setDescription('');
            }
        }

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                foreach ($operation->responses ?? [] as $response) {
                    if ($response instanceof Response) {
                        $this->addHeaders($response, $operation->method, $path->path);
                    }
                }
            }
        }
    }

    private function addHeaders(Response $response, string $method, string $path): void
    {
        $code = (int) $response->code;

        if ($code >= 200 && $code < 300) {
            $response->addHeader('X-RateLimit-Limit', self::header(new IntegerType, "This request's bucket limit per minute: 60 for `POST /v1/orders`, 120 for every `GET`."));
            $response->addHeader('X-RateLimit-Remaining', self::header(new IntegerType, 'Requests left in the current one-minute window.'));
        }

        if ($code === 200 && $method === 'post' && $path === 'v1/orders') {
            $response->addHeader('Idempotent-Replayed', self::header(new StringType, '`true` when this is a replay of an order already placed with the same `idempotency_key`.'));
        }

        if ($code === 429) {
            $response->addHeader('Retry-After', self::header(new IntegerType, 'Seconds to wait before retrying.'));
        }
    }

    private static function header(IntegerType|StringType $type, string $description): Header
    {
        return (new Header(description: $description))->setSchema(Schema::fromType($type));
    }
}
