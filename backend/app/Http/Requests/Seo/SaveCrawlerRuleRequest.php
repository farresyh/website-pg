<?php

namespace App\Http\Requests\Seo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ADR-029 addendum 2 decision 14: per-bot robots.txt rule. */
class SaveCrawlerRuleRequest extends FormRequest
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
            'bot_name' => ['required', 'string', 'max:255'],
            'user_agent' => [
                'required',
                'string',
                'max:255',
                Rule::unique('crawler_rules', 'user_agent')->ignore($this->route('crawler_rule')),
            ],
            'is_allowed' => ['required', 'boolean'],
            'crawl_delay' => ['nullable', 'integer', 'min:0'],
            'disallow_paths' => ['nullable', 'array'],
            'disallow_paths.*' => ['string', 'starts_with:/'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
