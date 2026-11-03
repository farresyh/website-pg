<?php

namespace App\Http\Requests\CsKnowledge;

use App\Models\CsAssistantSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ADR-136 decisions 7, 11, 22, 25 and 27: the assistant's panel settings. Live is PR 4. */
class UpdateCsAssistantSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $models = array_keys((array) config('services.cs_assistant.models'));

        return [
            'mode' => ['required', Rule::in([CsAssistantSettings::MODE_OFF, CsAssistantSettings::MODE_SHADOW])],
            'answer_model' => ['required', Rule::in($models)],
            // Decision 33: the judge is never the model it grades.
            'judge_model' => ['required', Rule::in($models), 'different:answer_model'],
            'reassure_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'handover_minutes' => ['required', 'integer', 'min:1', 'max:1440', 'gt:reassure_minutes'],
            'manual_handover_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'burst_wait_seconds' => ['required', 'integer', 'min:5', 'max:600'],
            'daily_cost_cap_sen' => ['required', 'integer', 'min:100', 'max:100000'],
            'live_min_drafts' => ['required', 'integer', 'min:1', 'max:1000'],
            'live_min_accuracy_pct' => ['required', 'integer', 'min:50', 'max:100'],
        ];
    }
}
