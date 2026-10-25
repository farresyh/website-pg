<?php

namespace App\Services\Joki;

use App\Models\JokiTier;

/**
 * A validated rank on the ladder. `divisional`: `index` counts stars from
 * Epic's first; `grading`: Legend finished, Mythic Grading not played;
 * `mythic`: `mythicStars` on the Mythic star count.
 */
final readonly class JokiPosition
{
    public const DIVISIONAL = 'divisional';

    public const GRADING = 'grading';

    public const MYTHIC = 'mythic';

    public function __construct(
        public string $kind,
        public JokiTier $tier,
        public ?int $division,
        public ?int $stars,
        public int $index,
        public string $label,
    ) {}

    /** @return array{tier_id: int, tier_name: string, division: ?int, stars: ?int, label: string} */
    public function toArray(): array
    {
        return [
            'tier_id' => $this->tier->id,
            'tier_name' => $this->tier->name,
            'division' => $this->division,
            'stars' => $this->stars,
            'label' => $this->label,
        ];
    }
}
