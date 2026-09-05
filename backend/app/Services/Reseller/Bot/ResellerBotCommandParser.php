<?php

namespace App\Services\Reseller\Bot;

/**
 * PR-F build addendum decision 4 — the v1 command set, `.`-prefixed
 * (matching the reference-bot market convention ADR-075's own Context
 * cites): `.listharga` (directory of every game), `.list {code}` (one
 * game's packages), `.order {code} {playerId} [{serverId}]`, `.baki`
 * (balance). Space-separated, case-insensitive on the command word
 * itself; whitespace-collapsing so `.order  MLMY-14   123` still parses.
 *
 * ADR-076 decisions 7-9 add v2's `.trackorder {order_number}`,
 * `.checkid {code} {playerId} [{serverId}]` (same shape as `.order`,
 * minus a product code), and the argument-less `.info`. ADR-076 PR-H
 * adds `.topupbaki {amount}` (amount in RM, e.g. `.topupbaki 50`).
 *
 * Pure text-in, DTO-out — no DB/service call here, so it's trivially
 * unit-testable without a database.
 */
final class ResellerBotCommandParser
{
    public function parse(string $raw): ResellerBotCommand
    {
        $trimmed = trim($raw);
        $parts = preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return new ResellerBotCommand(ResellerBotCommandType::Unrecognized, $raw);
        }

        $command = strtolower($parts[0]);

        return match ($command) {
            '.listharga' => new ResellerBotCommand(ResellerBotCommandType::ListGames, $raw),

            '.list' => isset($parts[1])
                ? new ResellerBotCommand(ResellerBotCommandType::ListGamePackages, $raw, gameCode: strtoupper($parts[1]))
                : new ResellerBotCommand(ResellerBotCommandType::Unrecognized, $raw),

            '.baki' => new ResellerBotCommand(ResellerBotCommandType::Balance, $raw),

            '.info' => new ResellerBotCommand(ResellerBotCommandType::Info, $raw),

            '.topupbaki' => isset($parts[1])
                ? new ResellerBotCommand(ResellerBotCommandType::TopupBaki, $raw, amount: $parts[1])
                : new ResellerBotCommand(ResellerBotCommandType::Unrecognized, $raw),

            '.order' => isset($parts[1], $parts[2])
                ? new ResellerBotCommand(
                    ResellerBotCommandType::Order,
                    $raw,
                    productCode: strtoupper($parts[1]),
                    playerId: $parts[2],
                    serverId: $parts[3] ?? null,
                )
                : new ResellerBotCommand(ResellerBotCommandType::Unrecognized, $raw),

            '.trackorder' => isset($parts[1])
                ? new ResellerBotCommand(ResellerBotCommandType::TrackOrder, $raw, orderNumber: strtoupper($parts[1]))
                : new ResellerBotCommand(ResellerBotCommandType::Unrecognized, $raw),

            '.checkid' => isset($parts[1], $parts[2])
                ? new ResellerBotCommand(
                    ResellerBotCommandType::CheckId,
                    $raw,
                    gameCode: strtoupper($parts[1]),
                    playerId: $parts[2],
                    serverId: $parts[3] ?? null,
                )
                : new ResellerBotCommand(ResellerBotCommandType::Unrecognized, $raw),

            default => new ResellerBotCommand(ResellerBotCommandType::Unrecognized, $raw),
        };
    }
}
