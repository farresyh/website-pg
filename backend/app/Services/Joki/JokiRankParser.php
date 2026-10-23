<?php

namespace App\Services\Joki;

use App\Models\JokiTier;
use Illuminate\Validation\ValidationException;

/**
 * ADR-126 decision 10: a rank as a penjoki types it after `.progress`,
 * turned into the `{tier_id, division, stars}` JokiLadder validates.
 *
 *  - Below Mythic: `<tier> <division> <stars>`, the division in Roman
 *    numerals or digits (`Epic III 2`, `epic 3 2`).
 *  - Mythic and up: `<tier> <stars>` on the Mythic star count; the band
 *    is taken from the stars, so `Mythic 30` reads as Mythical Honor 30★.
 *
 * A tier is matched case- and space-insensitively on its name, its last
 * word (`Honor`), a prefix of three letters or more, or `GM`.
 */
final class JokiRankParser
{
    private const ALIASES = ['gm' => 'grandmaster'];

    private const ROMAN = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5, 'vi' => 6, 'vii' => 7, 'viii' => 8];

    public function __construct(private readonly JokiLadder $ladder) {}

    /** @return array{tier_id: int, division: ?int, stars: int} */
    public function parse(string $text): array
    {
        $tokens = preg_split('/\s+/', trim(mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stars = array_pop($tokens);

        if ($stars === null || ! ctype_digit($stars) || $tokens === []) {
            throw $this->invalid();
        }

        $division = $this->division(end($tokens));
        if ($division !== null && count($tokens) > 1) {
            $tier = $this->match(implode(' ', array_slice($tokens, 0, -1)));
            if ($tier !== null && $tier->isDivisional()) {
                return ['tier_id' => $tier->id, 'division' => $division, 'stars' => (int) $stars];
            }
        }

        $tier = $this->match(implode(' ', $tokens));
        if ($tier === null || $tier->isDivisional()) {
            throw $this->invalid();
        }

        return ['tier_id' => $this->ladder->mythicTierAt((int) $stars)->id, 'division' => null, 'stars' => (int) $stars];
    }

    private function match(string $input): ?JokiTier
    {
        $key = self::ALIASES[$this->normalize($input)] ?? $this->normalize($input);
        if (strlen($key) < 2) {
            return null;
        }

        $matches = $this->ladder->tiers()->filter(function (JokiTier $tier) use ($key) {
            $name = $this->normalize($tier->name);
            $last = $this->normalize((string) last(explode(' ', $tier->name)));

            return $key === $name || $key === $last
                || (strlen($key) >= 3 && (str_starts_with($name, $key) || str_starts_with($last, $key)));
        });

        // Several Mythic bands match `myth…`: the stars pick the band anyway.
        if ($matches->count() > 1 && $matches->every(fn (JokiTier $t) => ! $t->isDivisional())) {
            return $matches->first();
        }

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function division(string|false $token): ?int
    {
        if ($token === false) {
            return null;
        }

        return ctype_digit($token) ? (int) $token : (self::ROMAN[$token] ?? null);
    }

    private function normalize(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s)) ?? '';
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['rank' => ['Rank not recognised. Examples: Epic III 2, Legend 1 4, Mythic 30.']]);
    }
}
