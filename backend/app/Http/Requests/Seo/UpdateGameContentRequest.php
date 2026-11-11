<?php

namespace App\Http\Requests\Seo;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-140 decision 6: a game's own page content. Plain text, all optional; the
 * limits are the decision's. "Nothing to publish" is a cross-field rule and
 * lives in Admin\GameContentController.
 */
class UpdateGameContentRequest extends FormRequest
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
            'summary' => ['nullable', 'string', 'max:300'],
            'about' => ['nullable', 'string', 'max:2000'],
            'faq' => ['nullable', 'array', 'max:8'],
            'faq.*.question' => ['required', 'string', 'max:150'],
            'faq.*.answer' => ['required', 'string', 'max:500'],
            'published' => ['required', 'boolean'],
        ];
    }
}
