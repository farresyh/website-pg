<?php

namespace App\Http\Requests\CsKnowledge;

use App\Models\CsBotDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ADR-136 decision 18: a super_admin overrules the judge's grade (null clears the overrule). */
class GradeCsBotDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['grade' => ['present', 'nullable', Rule::in(CsBotDraft::GRADES)]];
    }
}
