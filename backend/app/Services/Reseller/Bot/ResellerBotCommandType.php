<?php

namespace App\Services\Reseller\Bot;

/**
 * PR-F build addendum decision 4 — the v1 command set, pinned.
 * ADR-076 decisions 7-9 add `TrackOrder`/`CheckId`/`Info` — v2.
 */
enum ResellerBotCommandType
{
    case ListGames;      // .listharga
    case ListGamePackages; // .list {reseller_code}
    case Order;           // .order {code} {playerId} [{serverId}]
    case Balance;         // .baki
    case TrackOrder;       // .trackorder {order_number}
    case CheckId;          // .checkid {reseller_code} {playerId} [{serverId}]
    case Info;             // .info
    case Unrecognized;
}
