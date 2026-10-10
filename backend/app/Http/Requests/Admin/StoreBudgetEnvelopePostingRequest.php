<?php

namespace App\Http\Requests\Admin;

use App\Services\Accounting\EnvelopePostingType;
use App\Services\Accounting\ExpenseCategory;
use App\Services\Accounting\FundType;
use App\Services\Accounting\PaidFrom;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-083 2026-10-10 addendum — structure only. What each posting type
 * requires (which fields, line signs, sums) is the service's call
 * (`BudgetEnvelopeService::post()`), so the rule lives in one place.
 */
class StoreBudgetEnvelopePostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $manualTypes = array_map(fn (EnvelopePostingType $t) => $t->value, array_filter(EnvelopePostingType::cases(), fn (EnvelopePostingType $t) => $t->isManual()));

        return [
            'type' => ['required', 'string', Rule::in($manualTypes)],
            // KL today — the app clock is UTC (item 63). Required: a back-dated entry must never silently claim today.
            'transaction_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(ReportService::TIMEZONE)->toDateString()],
            'description' => ['required', 'string', 'max:1000'],
            'counterparty' => ['nullable', 'string', Rule::in(array_map(fn (PaidFrom $p) => $p->value, PaidFrom::directors()))],
            'fund_type' => ['nullable', 'string', Rule::enum(FundType::class)],
            'expense_category' => ['nullable', 'string', Rule::enum(ExpenseCategory::class)],
            'reference_no' => ['nullable', 'string', 'max:191'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.budget_envelope_id' => ['required', 'integer', 'exists:budget_envelopes,id'],
            'lines.*.amount_sen' => ['required', 'integer', 'between:-1000000000,1000000000', 'not_in:0'],
        ];
    }
}
