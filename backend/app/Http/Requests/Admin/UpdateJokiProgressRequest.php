<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-126 decision 10: a rank from Order Detail; JokiLadder checks it against the model. */
class UpdateJokiProgressRequest extends FormRequest
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
            'tier_id' => ['required', 'integer'],
            'division' => ['nullable', 'integer', 'min:1', 'max:9'],
            'stars' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
