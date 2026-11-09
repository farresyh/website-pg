<?php

namespace App\Services\Rebate;

use App\Models\ResellerRebateLadder;
use App\Models\ResellerTier;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * ADR-139 decisions 3, 6, 12 and the terms addendum's notice rule: the one
 * writer of ladder versions. A version is never edited; saving one makes a
 * new row effective from the first 1st (KL) at least 7 days away.
 */
final class RebateLadderService
{
    /** Decision 12: the hard ceiling no panel value can exceed, in basis points. */
    public const HARD_CEILING_BP = 5000;

    public const NOTICE_DAYS = 7;

    /**
     * @param  list<array{threshold_sen: int, rewards: array<int|string, int>}>  $steps  cumulative sen per tier id
     */
    public function saveVersion(array $steps, int $maxShareBp, ?int $adminUserId): ResellerRebateLadder
    {
        $this->validate($steps, $maxShareBp);

        return ResellerRebateLadder::query()->create([
            'effective_from' => self::nextEffectiveFrom()->toDateString(),
            'max_share_bp' => $maxShareBp,
            'steps' => array_map(fn (array $s) => [
                'threshold_sen' => (int) $s['threshold_sen'],
                'rewards' => collect($s['rewards'])->mapWithKeys(fn ($v, $k) => [(string) $k => (int) $v])->all(),
            ], array_values($steps)),
            'admin_user_id' => $adminUserId,
        ]);
    }

    /** The version in force for a month, or null (no rebate that month). */
    public function forMonth(CarbonInterface $monthStart): ?ResellerRebateLadder
    {
        return ResellerRebateLadder::query()
            ->whereDate('effective_from', '<=', $monthStart->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();
    }

    public static function nextEffectiveFrom(): CarbonImmutable
    {
        $today = CarbonImmutable::now(MalaysiaTime::TIMEZONE)->startOfDay();
        $first = $today->addMonthNoOverflow()->startOfMonth();

        return $today->diffInDays($first) < self::NOTICE_DAYS ? $first->addMonthNoOverflow() : $first;
    }

    /** @param  list<array{threshold_sen: int, rewards: array<int|string, int>}>  $steps */
    private function validate(array $steps, int $maxShareBp): void
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['ladder' => [$m]]);

        if ($maxShareBp < 1 || $maxShareBp > self::HARD_CEILING_BP) {
            $fail('The monthly cap must be between 0.01% and 50% of profit.');
        }

        $markups = ResellerTier::query()->pluck('markup_percent', 'id');
        // Every step rewards the same tiers: a missing cell would read as 0 and make a column fall.
        $tiers = fn (array $step) => collect(array_keys($step['rewards'] ?? []))->map(fn ($k) => (string) $k)->sort()->values()->all();
        $firstTiers = $steps === [] ? [] : $tiers(array_values($steps)[0]);
        $previousThreshold = 0;
        $previous = [];

        foreach (array_values($steps) as $i => $step) {
            if ($tiers($step) !== $firstTiers) {
                $fail('Step '.($i + 1).' must reward the same tiers as step 1.');
            }
            $threshold = (int) ($step['threshold_sen'] ?? 0);
            if ($threshold <= $previousThreshold) {
                $fail('Step '.($i + 1).': thresholds must be above zero and rise step by step.');
            }

            foreach ($step['rewards'] ?? [] as $tierId => $cumulative) {
                $cumulative = (int) $cumulative;
                if (! isset($markups[$tierId])) {
                    $fail('Step '.($i + 1).": tier {$tierId} does not exist.");
                }
                if ($cumulative < ($previous[$tierId] ?? 0)) {
                    $fail('Step '.($i + 1).': a tier\'s cumulative reward can never fall.');
                }
                // On paper, at the ceiling: the step's reward fits half the profit its threshold earns at that tier's markup.
                $m = (float) $markups[$tierId];
                if ($cumulative * (100 + $m) * 10000 > self::HARD_CEILING_BP * $threshold * $m) {
                    $fail('Step '.($i + 1).": tier {$tierId}'s reward is more than 50% of the profit its threshold earns.");
                }
                $previous[$tierId] = $cumulative;
            }
            $previousThreshold = $threshold;
        }
    }
}
