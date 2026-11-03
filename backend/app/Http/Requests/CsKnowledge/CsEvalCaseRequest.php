<?php

namespace App\Http\Requests\CsKnowledge;

use App\Models\CsEvalCase;
use App\Services\CsAssistant\CsAnswerer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ADR-136 decision 28: one model-test case (create, edit, and each row of an import). */
class CsEvalCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::caseRules();
    }

    /** @return array<string, mixed> */
    public static function caseRules(string $prefix = ''): array
    {
        return [
            "{$prefix}title" => ['required', 'string', 'max:120'],
            "{$prefix}kind" => ['required', Rule::in(CsEvalCase::KINDS)],
            "{$prefix}messages" => ['required', 'array', 'min:1', 'max:10'],
            "{$prefix}messages.*" => ['required', 'string', 'max:1000'],
            "{$prefix}affiliate_id" => ['nullable', 'integer', Rule::exists('affiliates', 'id')->whereNull('deleted_at')],
            "{$prefix}order_facts" => ['nullable', 'array'],
            "{$prefix}order_facts.payment_status" => ["required_with:{$prefix}order_facts", Rule::in(['pending', 'paid', 'failed'])],
            "{$prefix}order_facts.delivery_status" => ["required_with:{$prefix}order_facts", Rule::in(['not_started', 'pending', 'processing', 'delivered', 'failed', 'needs_review', 'partially_delivered'])],
            "{$prefix}order_facts.minutes_since_paid" => ['nullable', 'integer', 'min:0', 'max:100000'],
            "{$prefix}order_facts.expected_minutes" => ['nullable', 'integer', 'min:1', 'max:10000'],
            "{$prefix}order_facts.package" => ['nullable', 'string', 'max:120'],
            "{$prefix}order_facts.game" => ['nullable', 'string', 'max:120'],
            "{$prefix}expected_action" => ['required', Rule::in(['reply', 'handover'])],
            "{$prefix}expected_reason" => ['nullable', Rule::in(CsAnswerer::HANDOVER_REASONS)],
            "{$prefix}must_not_contain" => ['nullable', 'array', 'max:20'],
            "{$prefix}must_not_contain.*" => ['required', 'string', 'max:100'],
            "{$prefix}notes" => ['nullable', 'string', 'max:2000'],
            "{$prefix}is_active" => ['sometimes', 'boolean'],
        ];
    }
}
