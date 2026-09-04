<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ADR-029 addendum 2 decision 14: per-bot robots.txt rule, deliberately not affiliate-scoped (see migration doc comment). */
class CrawlerRule extends Model
{
    protected $fillable = [
        'bot_name',
        'user_agent',
        'is_allowed',
        'crawl_delay',
        'disallow_paths',
        'is_custom',
        'sort_order',
    ];

    protected $casts = [
        'is_allowed' => 'boolean',
        'crawl_delay' => 'integer',
        'disallow_paths' => 'array',
        'is_custom' => 'boolean',
        'sort_order' => 'integer',
    ];
}
