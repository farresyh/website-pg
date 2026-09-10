<?php

namespace App\Http\Requests\ResellerApi;

use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-084 PR-2: `GET /api/reseller/v1/orders` — the caller's own order
 * history, cursor-paginated. `cursor` is the opaque Laravel cursor
 * string from a previous response's `next_cursor`; `status` filters on
 * the delivery-status values the order responses already expose;
 * `created_after` is an ISO-8601 lower bound on `created_at`.
 */
class IndexOrdersRequest extends FormRequest
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
            'cursor' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', Rule::enum(DeliveryStatus::class)],
            'created_after' => ['nullable', 'date'],
        ];
    }
}
