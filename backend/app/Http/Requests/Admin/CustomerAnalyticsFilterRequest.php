<?php

namespace App\Http\Requests\Admin;

use App\Services\CustomerAnalytics\CustomerAnalyticsService;
use App\Services\CustomerAnalytics\CustomerFilters;
use App\Services\CustomerAnalytics\CustomerSegment;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Customer Analytics query filters (ADR-049 2026-10-15 addendum, decision
 * 10): a bad value is a 422, never silently dropped. Dates are Malaysia
 * calendar days, the same `from`/`to` Reports and Orders take.
 */
class CustomerAnalyticsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'affiliate_id' => ['nullable', 'integer', 'min:1'],
            'reseller_id' => ['nullable', 'integer', 'min:1'],
            'segment' => ['nullable', Rule::enum(CustomerSegment::class)],
            'search' => ['nullable', 'string', 'min:2', 'max:255'],
            'sort' => ['nullable', Rule::in(CustomerAnalyticsService::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in(CustomerAnalyticsService::PER_PAGE_OPTIONS)],
        ];
    }

    public function filters(): CustomerFilters
    {
        [$from, $toExclusive] = app(ReportService::class)->dateRangeFromDates(
            $this->validated('from'),
            $this->validated('to'),
        );

        return new CustomerFilters(
            from: $from,
            toExclusive: $toExclusive,
            affiliateId: $this->filled('affiliate_id') ? $this->integer('affiliate_id') : null,
            resellerId: $this->filled('reseller_id') ? $this->integer('reseller_id') : null,
            segment: $this->filled('segment') ? CustomerSegment::from($this->validated('segment')) : null,
            search: $this->filled('search') ? $this->validated('search') : null,
        );
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? 'total_spent';
    }
}
