<?php

namespace App\Http\Requests\Onboarding;

use App\Services\PartnerApplication\PartnerApplicationDocuments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-133 decision 8: PDF, JPG or PNG up to 10 MB. Laravel's `mimes` rule checks the type read
 * from the file's content, not the name the browser sent.
 */
class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(PartnerApplicationDocuments::LIMITS))],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
