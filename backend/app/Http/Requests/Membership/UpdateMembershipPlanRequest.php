<?php

namespace App\Http\Requests\Membership;

use App\Models\MembershipPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-027's 2026-08-29 addendum, decision 19: cross-tier validation —
 * the tier with the smaller id (Tier 1, seeded first) must always keep
 * a strictly smaller discount_percent than the other fixed row (Tier
 * 2). Protects decision 4's anchor/decoy pricing from an admin data
 * -entry mistake silently inverting which tier is the better deal.
 * There are only ever two rows (decision 15), so "the other row" is
 * unambiguous.
 */
class UpdateMembershipPlanRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'fee_sen' => ['required', 'integer', 'min:0'],
            'quota_sen' => ['required', 'integer', 'min:0'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var MembershipPlan $current */
            $current = $this->route('membershipPlan');
            $other = MembershipPlan::where('id', '!=', $current->id)->first();

            if ($other === null) {
                return;
            }

            $newDiscount = (float) $this->input('discount_percent');
            $isLowerTier = $current->id < $other->id;
            $valid = $isLowerTier
                ? $newDiscount < (float) $other->discount_percent
                : $newDiscount > (float) $other->discount_percent;

            if (! $valid) {
                $validator->errors()->add(
                    'discount_percent',
                    $isLowerTier
                        ? 'This tier\'s discount must stay below the higher tier\'s discount.'
                        : 'This tier\'s discount must stay above the lower tier\'s discount.',
                );
            }
        });
    }
}
