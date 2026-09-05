<?php

namespace Tests\Unit\Services\Reseller\Bot;

use App\Services\Reseller\Bot\ResellerBotCommandParser;
use App\Services\Reseller\Bot\ResellerBotCommandType;
use Tests\TestCase;

/** PR-F build addendum decision 4 — the v1 command set. Pure text-in/DTO-out, no DB. */
class ResellerBotCommandParserTest extends TestCase
{
    private ResellerBotCommandParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ResellerBotCommandParser;
    }

    public function test_parses_listharga(): void
    {
        $command = $this->parser->parse('.listharga');

        $this->assertSame(ResellerBotCommandType::ListGames, $command->type);
    }

    public function test_parses_list_with_a_game_code_uppercased(): void
    {
        $command = $this->parser->parse('.list mlmy');

        $this->assertSame(ResellerBotCommandType::ListGamePackages, $command->type);
        $this->assertSame('MLMY', $command->gameCode);
    }

    public function test_bare_list_with_no_game_code_is_unrecognized(): void
    {
        $command = $this->parser->parse('.list');

        $this->assertSame(ResellerBotCommandType::Unrecognized, $command->type);
    }

    public function test_parses_baki(): void
    {
        $command = $this->parser->parse('.baki');

        $this->assertSame(ResellerBotCommandType::Balance, $command->type);
    }

    public function test_parses_order_with_server_id(): void
    {
        $command = $this->parser->parse('.order mlmy-14 51049607 2005');

        $this->assertSame(ResellerBotCommandType::Order, $command->type);
        $this->assertSame('MLMY-14', $command->productCode);
        $this->assertSame('51049607', $command->playerId);
        $this->assertSame('2005', $command->serverId);
    }

    public function test_parses_order_without_server_id(): void
    {
        $command = $this->parser->parse('.order ffmy-100 123456789');

        $this->assertSame(ResellerBotCommandType::Order, $command->type);
        $this->assertNull($command->serverId);
    }

    public function test_order_missing_player_id_is_unrecognized(): void
    {
        $command = $this->parser->parse('.order mlmy-14');

        $this->assertSame(ResellerBotCommandType::Unrecognized, $command->type);
    }

    public function test_collapses_extra_whitespace(): void
    {
        $command = $this->parser->parse('.order   mlmy-14   51049607   2005');

        $this->assertSame('51049607', $command->playerId);
        $this->assertSame('2005', $command->serverId);
    }

    public function test_unknown_command_word_is_unrecognized(): void
    {
        $command = $this->parser->parse('.blahblah');

        $this->assertSame(ResellerBotCommandType::Unrecognized, $command->type);
    }

    public function test_blank_input_is_unrecognized(): void
    {
        $command = $this->parser->parse('   ');

        $this->assertSame(ResellerBotCommandType::Unrecognized, $command->type);
    }
}
