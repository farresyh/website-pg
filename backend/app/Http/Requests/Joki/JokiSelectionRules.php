<?php

namespace App\Http\Requests\Joki;

/**
 * ADR-126 decisions 3 and 12: the structural shape of a joki selection,
 * shared by checkout, its preview and the `/joki` quote. Ranks are checked
 * against the panel's model by JokiLadder; a price is never accepted.
 */
final class JokiSelectionRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $rank = fn (string $side) => [
            "joki.{$side}" => ['nullable', 'array'],
            "joki.{$side}.tier_id" => ['nullable', 'integer'],
            "joki.{$side}.division" => ['nullable', 'integer', 'min:1', 'max:9'],
            "joki.{$side}.stars" => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];

        return [
            'joki' => ['nullable', 'array'],
            ...$rank('from'),
            ...$rank('to'),
            'joki.is_express' => ['nullable', 'boolean'],
            'joki.hero_request' => ['nullable', 'string', 'max:255'],
            'joki.role' => ['nullable', 'string', 'max:50'],
            'joki.play_window' => ['nullable', 'string', 'max:255'],
        ];
    }
}
