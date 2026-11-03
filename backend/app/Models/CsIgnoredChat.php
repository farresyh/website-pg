<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-136 decision 16: a CS chat an admin chose to ignore (founder or staff
 * tests). Keyed by `cs_messages.chat_hash`, so it works for `@lid` chats too.
 */
class CsIgnoredChat extends Model
{
    protected $fillable = ['chat_hash', 'ignored_by'];
}
