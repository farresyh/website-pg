<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

class ApproveAllPendingPriceChangesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['supplier_id' => ['required', 'integer', 'exists:suppliers,id']];
    }
}
