<?php

namespace App\Http\Requests\Seo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ADR-029 addendum 2 decision 13: basic tag-balance validation only —
 * a trusted-admin-only text field, deliberately not a full HTML/JS
 * parser (own Consequence to track).
 */
class SaveSeoScriptRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'location' => ['required', Rule::in(['head', 'body_end'])],
            'code' => ['required', 'string'],
            'priority' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'reseller_id' => ['nullable', 'integer', Rule::exists('resellers', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $code = $this->input('code', '');
            $openTags = preg_match_all('/<script\b[^>]*>/i', $code);
            $closeTags = substr_count(strtolower($code), '</script>');

            if ($openTags !== $closeTags) {
                $validator->errors()->add('code', 'Unbalanced <script> tags.');
            }
        });
    }
}
