<?php

namespace App\Http\Requests\Voucher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-024 decision #1's "Apply" button — structural validation only.
 * Never accepts a discount amount, only the code + the caller's own
 * identity; VoucherPreviewController resolves everything else
 * (package price, ownership match) server-side.
 */
class PreviewVoucherRequest extends FormRequest
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
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'voucher_code' => ['required', 'string', 'max:32'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
        ];
    }
}
