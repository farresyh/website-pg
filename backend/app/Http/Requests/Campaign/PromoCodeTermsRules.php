<?php

namespace App\Http\Requests\Campaign;

/** ADR-118 V3/V11/V17: the editable terms of a promo code, shared by create and edit. */
final class PromoCodeTermsRules
{
    /** @return array<string, mixed> */
    public static function rules(bool $required): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            'discount_percent' => [$req, 'numeric', 'min:0.01', 'max:50'],
            'budget_sen' => [$req, 'integer', 'min:1', 'max:100000000'],
            'max_discount_sen' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
