<?php

namespace App\Http\Requests\Membership;

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
