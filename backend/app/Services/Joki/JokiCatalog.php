<?php

namespace App\Services\Joki;

use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * ADR-126 decision 1: the joki game and its calculator package, created
 * once when joki is set up (the admin's joki switch, PR 4) rather than by
 * a migration, so no other catalog, count or test meets them before joki
 * exists. Idempotent: it finds what is already there.
 *
 * The game is a login game (the MLBB login form, kept 30 days, decision
 * 22), in no catalog (`is_joki`), with no member pricing; the joki switch
 * is its only switch, so it is created active. The calculator package
 * points at the assignment pool; its price fields are never read (F1).
 */
final class JokiCatalog
{
    public const GAME_SLUG = 'joki-mlbb';

    public const CALCULATOR_REF = 'JOKI-RANKED';

    public function ensure(): Package
    {
        return DB::transaction(function () {
            $game = Game::query()->joki()->lockForUpdate()->first() ?? Game::query()->create([
                'name' => 'Joki MLBB',
                'slug' => self::GAME_SLUG,
                'is_active' => true,
                'is_joki' => true,
                'delivery_mode' => 'manual',
                'delivery_subtext' => 'Played by our penjoki',
                'member_pricing_enabled' => false,
                'credential_max_days' => 30,
                'validation_rules' => ['credential_fields' => [
                    ['key' => 'login_type', 'label' => 'Login type', 'type' => 'select', 'required' => true, 'secret' => false, 'is_identifier' => false,
                        'options' => ['Moonton', 'Facebook', 'VK', 'TikTok', 'Google Play']],
                    ['key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'required' => true, 'secret' => false, 'is_identifier' => false],
                    ['key' => 'login', 'label' => 'Email or username', 'type' => 'text', 'required' => true, 'secret' => false, 'is_identifier' => true],
                    ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'secret' => true, 'is_identifier' => false],
                ]],
            ]);

            return Package::query()->firstOrCreate(
                ['game_id' => $game->id, 'supplier_package_ref' => self::CALCULATOR_REF],
                [
                    'name' => 'Joki Ranked',
                    'cost_price' => 0,
                    'standard_selling_price' => 0,
                    'supplier_id' => Supplier::assignmentPool()->id,
                    'is_active' => true,
                ],
            );
        });
    }

    /** The calculator package, if joki was ever set up. */
    public function calculator(): ?Package
    {
        return Package::query()
            ->where('supplier_package_ref', self::CALCULATOR_REF)
            ->whereHas('game', fn ($q) => $q->joki())
            ->first();
    }
}
