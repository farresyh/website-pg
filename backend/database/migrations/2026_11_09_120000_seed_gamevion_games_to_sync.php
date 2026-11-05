<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * ADR-137 2026-11-05 decision 1: the first games-to-sync list, the Gamevion
 * games in every family PekanGame already sells (read from gamevion.cloud on
 * 2026-11-05). Written once, only while `games` is absent, so a list the
 * founder has edited in the picker is never overwritten. Left out:
 * `call_of_duty_mobile_my` (no player field), `arena_breakout_infinite`
 * (the PC game), and `valorant` / `blood_strike_pass` (down at Gamevion that
 * day; tick them in the picker once they answer).
 */
return new class extends Migration
{
    private const GAMES = [
        'aniimo', 'arena_breakout',
        'bloodstrike', 'bloodstrike_id', 'bloodstrike_ph', 'bloodstrikeme',
        'call_of_duty_mobile_in', 'codm_sgmy', 'codm_my_garena_uid', 'call_of_duty_mobile_indonesia_id',
        'freefire_cis', 'free_fire_instant_id', 'freefire_mysg', 'free_fire_instant_my', 'freefire_ph', 'freefire_th',
        'freefire_bd', 'freefire_br', 'freefire_eu', 'freefire_global', 'freefire_id', 'freefire_latam', 'freefire_me',
        'freefire_sg', 'freefire_tw', 'freefire_vn', 'freefire_sgmy',
        'gstm', 'genshin_global', 'hsr_global', 'hsr_us', 'hok', 'kingshot',
        'magic_chess_go_go_global', 'magic_chess_go_go_id', 'magic_chest_gogo', 'mcgg_my', 'mcgg_ph', 'mcgg_ru', 'mcgg_tr',
        'mlbb_br', 'mlbb_id', 'mlbb_sgmy', 'mlbb_ph', 'mlbb_ru', 'mlbb_tr', 'mlbb_us', 'mlbb', 'mlbb_exclusive',
        'mlbb_globalindo', 'mlbb_sg', 'mlbb_special',
        'pubgm_auto', 'pubgm', 'pubgm_reserve', 'pubgm_global', 'pubgm_fast',
        'racing_master_id', 'racing_master_ph', 'racing_master_latam', 'racing_master_sea', 'racing_master_na',
        'rainbow_six_mobile_global', 'rainbow_six_mobile_sg',
        'valorant_id', 'valorant_kh', 'valorant_my', 'valorant_ph', 'valorant_sg', 'valorant_th', 'valorant_vn',
        'wwm', 'wuwa', 'zzz_global',
    ];

    public function up(): void
    {
        $row = DB::table('suppliers')->where('slug', 'gamevion')->first(['id', 'api_config']);

        if ($row === null || $row->api_config === null) {
            return;
        }

        // The model's `encrypted:array` cast, read and written by hand.
        $config = json_decode(Crypt::decryptString($row->api_config), true) ?: [];

        if (array_key_exists('games', $config)) {
            return;
        }

        $config['games'] = self::GAMES;

        DB::table('suppliers')->where('id', $row->id)->update(['api_config' => Crypt::encryptString(json_encode($config))]);
    }

    public function down(): void
    {
        // The list may have been edited in the picker since; leave it.
    }
};
