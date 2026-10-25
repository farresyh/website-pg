<?php

namespace App\Services\Joki;

use App\Models\JokiSetting;
use App\Models\JokiTier;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ADR-126 decision 3: the panel's rank model as one ladder. Below Mythic a
 * rank is a star position: a division is worth its own stars, counted
 * down (Epic V 0★ → Epic IV 0★ = 5 stars, founder 2026-10-23), and a
 * division's stars run 0 … stars_per_division − 1. Legend finished is the
 * Mythic Grading state. Mythic and up is the Mythic star count, each band
 * starting at its tier's `mythic_from_stars`.
 */
final class JokiLadder
{
    /** @var Collection<int, JokiTier> */
    private Collection $divisional;

    /** @var Collection<int, JokiTier> */
    private Collection $mythic;

    /** @var array<int, int> tier id => first star index */
    private array $offsets = [];

    private int $belowMythicStars = 0;

    /** @param  Collection<int, JokiTier>  $tiers */
    public function __construct(Collection $tiers, public readonly JokiSetting $settings)
    {
        $sorted = $tiers->sortBy('sort_order')->values();
        $this->divisional = $sorted->filter->isDivisional()->values();
        $this->mythic = $sorted->reject->isDivisional()->sortBy('mythic_from_stars')->values();

        foreach ($this->divisional as $tier) {
            $this->offsets[$tier->id] = $this->belowMythicStars;
            $this->belowMythicStars += $tier->divisions * $tier->stars_per_division;
        }
    }

    public static function current(): self
    {
        return new self(JokiTier::query()->get(), JokiSetting::current());
    }

    /** @return Collection<int, JokiTier> */
    public function tiers(): Collection
    {
        return $this->divisional->concat($this->mythic);
    }

    /** Stars from the lowest tier's first (Epic V 0★) to Legend finished: the Grading state's index. */
    public function belowMythicStars(): int
    {
        return $this->belowMythicStars;
    }

    /**
     * @param  array<string, mixed>  $rank  `{tier_id, division, stars}`; on the lowest Mythic tier `stars = null` is Grading
     */
    public function position(array $rank, string $field): JokiPosition
    {
        $tier = $this->tiers()->firstWhere('id', (int) ($rank['tier_id'] ?? 0));
        $division = isset($rank['division']) ? (int) $rank['division'] : null;
        $stars = isset($rank['stars']) ? (int) $rank['stars'] : null;

        if ($tier === null) {
            throw $this->invalid($field, 'Choose a rank from the list.');
        }

        if ($tier->isDivisional()) {
            if ($division === null || $division < 1 || $division > $tier->divisions
                || $stars === null || $stars < 0 || $stars >= $tier->stars_per_division) {
                throw $this->invalid($field, "{$tier->name} has divisions {$this->roman($tier->divisions)} to I and 0 to ".($tier->stars_per_division - 1).' stars.');
            }

            $index = $this->offsets[$tier->id] + ($tier->divisions - $division) * $tier->stars_per_division + $stars;

            return new JokiPosition(JokiPosition::DIVISIONAL, $tier, $division, $stars, $index, "{$tier->name} {$this->roman($division)} {$stars}★");
        }

        if ($division !== null) {
            throw $this->invalid($field, "{$tier->name} has no divisions.");
        }

        if ($stars === null) {
            if ($tier->id !== $this->mythic->first()?->id) {
                throw $this->invalid($field, 'Choose a star count.');
            }

            return new JokiPosition(JokiPosition::GRADING, $tier, null, null, $this->belowMythicStars, "{$tier->name} (Grading)");
        }

        if ($stars > $this->settings->max_mythic_stars || $this->mythicTierAt($stars)->id !== $tier->id) {
            throw $this->invalid($field, "{$tier->name} is not {$stars}★.");
        }

        return new JokiPosition(JokiPosition::MYTHIC, $tier, null, $stars, $this->belowMythicStars + $stars, "{$tier->name} {$stars}★");
    }

    /** The divisional tier a star at this index is won in. */
    public function divisionalTierAt(int $index): JokiTier
    {
        foreach ($this->divisional->reverse() as $tier) {
            if ($index >= $this->offsets[$tier->id]) {
                return $tier;
            }
        }

        return $this->divisional->first();
    }

    /** The Mythic band a star at this Mythic star count is won in. */
    public function mythicTierAt(int $mythicStars): JokiTier
    {
        return $this->mythic->filter(fn (JokiTier $t) => $t->mythic_from_stars <= $mythicStars)->last() ?? $this->mythic->first();
    }

    public function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }

    private function roman(int $n): string
    {
        return [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII'][$n] ?? (string) $n;
    }
}
