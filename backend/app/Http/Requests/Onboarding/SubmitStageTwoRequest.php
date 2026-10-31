<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-133 decision 10: the stage-2 notice's own consent box. */
class SubmitStageTwoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['consent' => ['accepted']];
    }
}
