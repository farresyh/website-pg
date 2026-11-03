<?php

namespace App\Services\CsAssistant\Shadow;

use App\Models\CsAssistantSettings;
use App\Models\CsBotDraft;
use App\Models\CsKnowledgeSuggestion;
use App\Support\MalaysiaTime;
use Illuminate\Support\Facades\Log;

/**
 * ADR-136 decision 25: the daily cost cap. Every model call the assistant
 * makes outside a model test (drafts, judging, written suggestions) records
 * its cost on its own row; the Malaysia-day sum is compared with the cap.
 * Reaching it switches the mode Off. The test runs are founder-started and
 * not capped.
 */
final class CsSpend
{
    /** Today's spend (Malaysia day) in sen, rounded up. */
    public function todaySen(): int
    {
        $today = now(MalaysiaTime::TIMEZONE)->toDateString();
        $day = MalaysiaTime::dateSql('created_at');

        $drafts = (int) CsBotDraft::query()->whereRaw("{$day} = ?", [$today])->sum('cost_usd_micros');
        $judged = (int) CsBotDraft::query()->whereRaw(MalaysiaTime::dateSql('judged_at').' = ?', [$today])->sum('judge_cost_usd_micros');
        $written = (int) CsKnowledgeSuggestion::query()->whereRaw(MalaysiaTime::dateSql('ai_written_at').' = ?', [$today])->sum('ai_cost_usd_micros');

        return (int) ceil(($drafts + $judged + $written) / 1_000_000 * (float) config('services.cs_assistant.usd_to_myr', 4.5) * 100);
    }

    /**
     * True when another call may be made. At the cap the mode goes Off once
     * (the admin page shows when), and nothing more is called today.
     */
    public function allow(): bool
    {
        $settings = CsAssistantSettings::current();
        if ($this->todaySen() < $settings->daily_cost_cap_sen) {
            return true;
        }

        if ($settings->mode !== CsAssistantSettings::MODE_OFF) {
            $settings->update(['mode' => CsAssistantSettings::MODE_OFF, 'cost_cap_tripped_at' => now()]);
            Log::warning('CS assistant daily cost cap reached; mode switched Off', ['cap_sen' => $settings->daily_cost_cap_sen]);
        }

        return false;
    }
}
