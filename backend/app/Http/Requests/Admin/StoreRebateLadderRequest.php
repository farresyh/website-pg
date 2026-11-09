<?php

namespace App\Http\Requests\Admin;

use App\Services\Rebate\RebateLadderService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-139 decisions 3, 6, 12: a new ladder version's shape. The money rules
 * (rising thresholds, non-falling rewards, the same tiers at every step, the
 * 50% ceiling per tier) live in RebateLadderService, the one writer. An
 * empty `steps` list is a version with no rebate (the switch turned off).
 */
class StoreRebateLadderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'max_share_bp' => ['required', 'integer', 'min:1', 'max:'.RebateLadderService::HARD_CEILING_BP],
            'steps' => ['present', 'array', 'max:20'],
            'steps.*.threshold_sen' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'steps.*.rewards' => ['present', 'array'],
            'steps.*.rewards.*' => ['integer', 'min:0', 'max:100000000'],
        ];
    }
}
