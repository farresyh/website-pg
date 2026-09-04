<?php

namespace App\Services\Reseller\Bot;

/** PR-F build addendum decision 4 — the v1 command set, pinned. */
enum ResellerBotCommandType
{
    case ListGames;      // .listharga
    case ListGamePackages; // .list {reseller_code}
    case Order;           // .order {code} {playerId} [{serverId}]
    case Balance;         // .baki
    case Unrecognized;
}
