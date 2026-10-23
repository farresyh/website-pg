<?php

namespace App\Http\Requests\Admin\Joki;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-126 decision 5: a route package — its climb, and a whole-ringgit
 * promo price or a quotation. The controller checks the climb on the
 * ladder (from below to). Create needs everything; an update may send part.
 */
class SaveJokiRouteRequest extends FormRequest
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
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'from' => [$required, 'array'],
            'from.tier_id' => ['required_with:from', 'integer'],
            'from.division' => ['nullable', 'integer'],
            'from.stars' => ['nullable', 'integer'],
            'to' => [$required, 'array'],
            'to.tier_id' => ['required_with:to', 'integer'],
            'to.division' => ['nullable', 'integer'],
            'to.stars' => ['required_with:to', 'integer'],
            'is_quotation' => ['sometimes', 'boolean'],
            'price_rm' => $this->isMethod('post')
                ? ['nullable', 'integer', 'min:1', 'max:100000', 'required_unless:is_quotation,true']
                : ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
