<?php

namespace App\Console\Commands\CsAssistant;

use App\Services\CsAssistant\CsMessageRecorder;
use App\Services\CsAssistant\CsSenderClassifier;
use App\Services\OpenWa\OpenWaClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * ADR-136 decisions 8 and 19: copies the customer-support session's stored
 * history (personal chats only, masked) into `cs_messages`, so OpenWA's
 * 90-day prune (first around 2026-12-24) loses nothing. Read-only against
 * OpenWA. Safe to re-run: a message already kept is skipped.
 */
#[Signature('cs:import-history {--since=2026-09-25 : First day to import (Malaysia date)} {--page=100 : Messages per OpenWA request}')]
#[Description('Import the customer-support WhatsApp history into cs_messages (masked, personal chats only).')]
class ImportCsHistoryCommand extends Command
{
    public function handle(CsMessageRecorder $recorder, CsSenderClassifier $classifier): int
    {
        $since = CarbonImmutable::parse((string) $this->option('since'), 'Asia/Kuala_Lumpur')->startOfDay();
        $page = max(1, min(500, (int) $this->option('page')));
        // A fixed upper bound keeps offsets stable while new messages arrive.
        $until = CarbonImmutable::now();
        $client = OpenWaClient::customerSupport();

        $seen = 0;
        $kept = 0;
        for ($offset = 0; ; $offset += $page) {
            $rows = $client->messages([
                'since' => $since->getTimestampMs(),
                'until' => $until->getTimestampMs(),
                'orderBy' => 'timestamp',
                'limit' => $page,
                'offset' => $offset,
                'inlineMedia' => 'false',
            ]);

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $seen++;
                $kept += $recorder->recordImported($row, $classifier) ? 1 : 0;
            }
        }

        $this->info("Read {$seen} messages since {$since->toDateString()}; kept {$kept} new personal-chat messages.");

        return self::SUCCESS;
    }
}
