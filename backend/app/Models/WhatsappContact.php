<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-116 decision 5 and its 2026-11-01 addendum: one WhatsApp sender's
 * opt-in state, keyed by phone, by privacy-id chat (`…@lid`), or both.
 * Written only by CustomerWhatsAppInboundService. Rows are merged only when
 * OpenWA proves the chat is the phone (2026-11-03 addendum); otherwise, when a
 * phone and a chat each have a row, STOP on either wins.
 */
class WhatsappContact extends Model
{
    protected $fillable = [
        'phone',
        'chat_id',
        'opted_in_at',
        'opt_in_source',
        'opted_out_at',
    ];

    protected $casts = [
        'opted_in_at' => 'datetime',
        'opted_out_at' => 'datetime',
    ];

    /** Has this phone or chat ever written to us (opted in or out)? */
    public static function knows(?string $phone, ?string $chatId): bool
    {
        return static::matching($phone, $chatId)->exists();
    }

    /** Known, and no matching row has said STOP: may receive receipts and joki progress. */
    public static function receivesReceipts(?string $phone, ?string $chatId): bool
    {
        $rows = static::matching($phone, $chatId)->get(['opted_out_at']);

        return $rows->isNotEmpty() && $rows->every(fn (self $row) => $row->opted_out_at === null);
    }

    /** @return Builder<self> */
    public static function matching(?string $phone, ?string $chatId): Builder
    {
        return static::query()->where(function (Builder $query) use ($phone, $chatId) {
            $query->whereRaw('1 = 0');
            if ($phone !== null) {
                $query->orWhere('phone', $phone);
            }
            if ($chatId !== null) {
                $query->orWhere('chat_id', $chatId);
            }
        });
    }
}
