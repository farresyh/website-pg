<?php

namespace App\Http\Requests\Middleware;

use App\Models\BackupRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-039 "Backup Now", 2026-10-22 addendum: which source to back up.
 * Omitted means the database, as before.
 */
class StartBackupRunRequest extends FormRequest
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
            'source' => ['sometimes', 'string', Rule::in(BackupRun::SOURCES)],
        ];
    }
}
