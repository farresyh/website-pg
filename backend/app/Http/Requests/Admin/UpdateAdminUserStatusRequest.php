<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AdminUserController::updateStatus — extracted from an inline
 * $request->validate() 2026-08-21 to match this project's own
 * FormRequest-per-mutating-route convention (AGENTS.md).
 */
class UpdateAdminUserStatusRequest extends FormRequest
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
            'is_active' => ['required', 'boolean'],
        ];
    }
}
