<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ADR-136 decision 28: one model-test run over every active case. */
class CsEvalRun extends Model
{
    protected $fillable = ['models', 'cases_count', 'knowledge_count', 'started_by', 'revealed_at'];

    protected function casts(): array
    {
        return ['models' => 'array', 'revealed_at' => 'datetime'];
    }

    public function results(): HasMany
    {
        return $this->hasMany(CsEvalResult::class);
    }
}
