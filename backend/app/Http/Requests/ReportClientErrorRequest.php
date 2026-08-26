<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-044 decision 8 — structural validation only for the
 * `/api/client-errors` drift-report sink. Public (no auth, matches
 * every other guest-facing endpoint per ADR-011), so this only ever
 * enforces shape; nothing here is trusted as more than a hint for the
 * founder's own log visibility.
 */
class ReportClientErrorRequest extends FormRequest
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
            'schema' => ['required', 'string', 'max:100'],
            'path' => ['required', 'string', 'max:255'],
            'error' => ['required', 'string', 'max:2000'],
        ];
    }
}
