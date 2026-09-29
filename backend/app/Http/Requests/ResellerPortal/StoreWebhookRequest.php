<?php

namespace App\Http\Requests\ResellerPortal;

use App\Support\OutboundUrlGuard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-084 PR-3 decision 4/10: a Reseller portal user sets (or updates)
 * their single delivery-webhook endpoint URL. HTTPS only — a signed
 * payload is still worth protecting in transit, and every serious
 * receiver terminates TLS. Same shape `Admin\StoreResellerWebhookRequest`
 * uses for the support-side equivalent.
 */
class StoreWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url:https', 'max:2048', self::publicAddress(...)],
        ];
    }

    /** Wave 3 S-3 — shared with `Admin\StoreResellerWebhookRequest`. */
    public static function publicAddress(string $attribute, mixed $value, \Closure $fail): void
    {
        if (is_string($value) && app(OutboundUrlGuard::class)->publicAddressFor($value) === null) {
            $fail('The webhook URL must resolve to a public internet address.');
        }
    }
}
