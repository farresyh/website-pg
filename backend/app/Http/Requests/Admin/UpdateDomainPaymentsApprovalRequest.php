<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-131 decision 21: approve (true) or revoke (false) one domain's CHIP payments. */
class UpdateDomainPaymentsApprovalRequest extends FormRequest
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
            'approved' => ['required', 'boolean'],
        ];
    }
}
