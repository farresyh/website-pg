<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Onboarding\StoreStageOneRequest;
use App\Models\PartnerApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ADR-133 decision 6: Invite directly, someone the founder knows, straight to stage 2. */
class InvitePartnerApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'applicant_type' => ['required', Rule::in([PartnerApplication::TYPE_COMPANY, PartnerApplication::TYPE_INDIVIDUAL])],
            'owner_name' => ['required', 'string', 'max:255'],
            'business_name' => ['nullable', 'required_if:applicant_type,'.PartnerApplication::TYPE_COMPANY, 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'order_channel' => ['nullable', Rule::in(StoreStageOneRequest::ORDER_CHANNELS)],
        ];
    }
}
