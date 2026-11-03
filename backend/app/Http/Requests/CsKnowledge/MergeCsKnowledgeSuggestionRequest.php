<?php

namespace App\Http\Requests\CsKnowledge;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-136 decision 10: merge a suggestion into an existing entry as a new question variant. */
class MergeCsKnowledgeSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cs_knowledge_entry_id' => ['required', 'integer', 'exists:cs_knowledge_entries,id'],
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
