<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SYNC-6: bulk Approve/Dismiss on the Pending Reactivation queue
 * (ADR-015 decision #3). The frontend sends the ids it currently has
 * listed as pending — server re-validates each still exists as a
 * real Package, never assumes "all" server-side.
 */
class BulkPendingReactivationRequest extends FormRequest
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
            'package_ids' => ['required', 'array', 'min:1'],
            'package_ids.*' => ['integer', 'exists:packages,id'],
        ];
    }
}
