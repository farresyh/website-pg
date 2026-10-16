<?php

namespace Tests\Unit\Services\PlayerValidation;

use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\ZoneCheck;
use Tests\TestCase;

class ZoneCheckTest extends TestCase
{
    private const ZONES = ['Asia', 'America', 'Europe'];

    public function test_a_country_based_result_is_not_a_zone_check(): void
    {
        $this->assertNull(ZoneCheck::state(PlayerValidationResult::valid('redxgame', 'Prime.', 'MY'), '2005', null));
    }

    public function test_an_invalid_result_is_not_a_zone_check(): void
    {
        $this->assertNull(ZoneCheck::state(PlayerValidationResult::invalid('redxgame'), 'Asia', self::ZONES));
    }

    public function test_the_chosen_zone_matching_the_uids_server_is_valid(): void
    {
        $result = PlayerValidationResult::valid('redxgame', 'Lumine', null, server: 'Asia', zone: 'Asia');

        $this->assertSame('valid', ZoneCheck::state($result, 'Asia', self::ZONES));
    }

    public function test_a_different_chosen_zone_is_wrong_zone(): void
    {
        $result = PlayerValidationResult::valid('redxgame', 'Lumine', null, server: 'Americas', zone: 'America');

        $this->assertSame('wrong_zone', ZoneCheck::state($result, 'Asia', self::ZONES));
    }

    public function test_a_server_we_do_not_sell_is_wrong_zone_whatever_was_chosen(): void
    {
        $result = PlayerValidationResult::valid('redxgame', 'Lumine', null, server: 'China', zone: null);

        $this->assertSame('wrong_zone', ZoneCheck::state($result, 'Asia', self::ZONES));
    }

    public function test_a_zone_this_game_does_not_offer_is_wrong_zone(): void
    {
        $result = PlayerValidationResult::valid('redxgame', 'Lumine', null, server: 'Europe', zone: 'Europe');

        $this->assertSame('wrong_zone', ZoneCheck::state($result, 'Europe', ['Asia']));
        $this->assertNull(ZoneCheck::sellableZone($result, ['Asia']));
    }
}
