<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** PR-F build addendum decision 3: admin links a captured pending WhatsApp group id to a Reseller. */
class LinkResellerWhatsAppGroupRequest extends FormRequest
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
            'whatsapp_group_id' => ['required', 'string', 'max:255'],
        ];
    }
}
