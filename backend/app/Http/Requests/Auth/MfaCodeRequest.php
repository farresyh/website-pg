<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** ADR-121: a 6-digit TOTP code or a recovery code (`xxxxx-xxxxx`). */
class MfaCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the login step and step-up each check their own state
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
        ];
    }
}
