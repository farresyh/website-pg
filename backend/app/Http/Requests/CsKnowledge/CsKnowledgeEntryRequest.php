<?php

namespace App\Http\Requests\CsKnowledge;

use App\Models\CsKnowledgeEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ADR-136 decision 17: an approved knowledge entry (create, edit, approve a suggestion). */
class CsKnowledgeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(CsKnowledgeEntry::CATEGORIES)],
            'questions' => ['required', 'array', 'min:1', 'max:20'],
            'questions.*' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string', 'max:2000'],
            'affiliate_id' => ['nullable', 'integer', Rule::exists('affiliates', 'id')->whereNull('deleted_at')],
            'game_id' => ['nullable', 'integer', 'exists:games,id'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
