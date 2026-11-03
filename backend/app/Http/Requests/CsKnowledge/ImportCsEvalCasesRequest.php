<?php

namespace App\Http\Requests\CsKnowledge;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-136 decision 28: a batch of model-test cases pasted as JSON. */
class ImportCsEvalCasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cases' => ['required', 'array', 'min:1', 'max:200'], ...CsEvalCaseRequest::caseRules('cases.*.')];
    }
}
