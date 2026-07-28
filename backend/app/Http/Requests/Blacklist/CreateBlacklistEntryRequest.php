<?php

namespace App\Http\Requests\Blacklist;

use App\Services\Fraud\BlacklistEntryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FRAUD-3: reason is required - never an optional field, matching
 * this codebase's existing discipline for blacklist/adjustment-style
 * actions (see ledger_entries.reason for `adjustment` rows).
 */
class CreateBlacklistEntryRequest extends FormRequest
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
            'type' => ['required', Rule::in(array_column(BlacklistEntryType::cases(), 'value'))],
            'value' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
