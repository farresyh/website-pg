<?php

namespace App\Http\Requests\Membership;

use App\Models\Affiliate;
use App\Models\MembershipPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-027 continued addendum decision 15 / Phase 6.5 (grilled
 * 2026-08-29) — the admin "Record Payment" action's validation.
 *
 * Q4: `amount_sen` is pre-filled from the chosen plan's `fee_sen` by the
 * client, but editable — any deviation from the plan's fee requires a
 * `reason` (audit trail for partial payments / promos / waivers), so a
 * non-standard amount can never be silently recorded. Q13: `min:0`
 * deliberately allows a zero-amount waiver. Q11: `idempotency_key` is a
 * required per-open UUID the service uses to refuse double-bookings.
 */
class RecordMembershipPaymentRequest extends FormRequest
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
            // ADR-061 decision 5: a membership belongs to one brand. The
            // admin picks which; the storefront selector only lists brands
            // that can actually run Membership (checked below).
            'affiliate_id' => ['required', 'integer', 'exists:affiliates,id'],
            'email' => ['required', 'email', 'max:255'],
            'membership_plan_id' => ['required', 'integer', 'exists:membership_plans,id'],
            'amount_sen' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $validator->errors()->has('affiliate_id')) {
                $affiliate = Affiliate::query()->find($this->integer('affiliate_id'));

                // Mirrors the RES-2/RES-3 rule (ADR-061 build addendum):
                // consumer Membership is internal-brand-only, and only
                // when the brand's own toggle is on.
                if ($affiliate !== null && ! ($affiliate->is_owned && $affiliate->membership_enabled)) {
                    $validator->errors()->add(
                        'affiliate_id',
                        'This brand does not have consumer Membership enabled.',
                    );
                }
            }

            if ($validator->errors()->hasAny(['amount_sen', 'membership_plan_id'])) {
                return;
            }

            $plan = MembershipPlan::query()->find($this->integer('membership_plan_id'));

            if ($plan !== null
                && (int) $this->input('amount_sen') !== $plan->fee_sen
                && $this->filled('reason') === false) {
                $validator->errors()->add(
                    'reason',
                    'A reason is required when the amount differs from the plan\'s fee.',
                );
            }
        });
    }
}
