<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-087 decision 6/7 — role check itself lives in the route's
 * admin.role:super_admin middleware, not here. `history` is the entire
 * chat-session state (decision 7: session-scoped only, never persisted
 * server-side) — the client resends it every turn, capped here to keep
 * one request's Gemini prompt bounded.
 */
class AskReportAssistantRequest extends FormRequest
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
            'question' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes', 'array', 'max:20'],
            'history.*.question' => ['required_with:history', 'string', 'max:2000'],
            'history.*.answer' => ['required_with:history', 'string', 'max:20000'],
        ];
    }
}
