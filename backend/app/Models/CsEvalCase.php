<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ADR-136 decision 28: one model-test case, super_admin only, never in the repo. */
class CsEvalCase extends Model
{
    public const KINDS = ['safety', 'quality'];

    protected $fillable = ['title', 'kind', 'messages', 'affiliate_id', 'order_facts', 'expected_action', 'expected_reason', 'must_not_contain', 'notes', 'is_active'];

    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'order_facts' => 'array',
            'must_not_contain' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
