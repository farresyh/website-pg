<?php

namespace Tests\Feature\Services\Supplier;

use App\Jobs\LogSupplierRequestJob;
use App\Services\Supplier\Gamevion\GamevionAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierOutcome;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ADR-137: GamevionAdapter against gamevion.cloud (document v62.25).
 * Queue::fake() keeps LogSupplierRequestJob from touching a DB this suite
 * never migrates; Sleep::fake() skips the pricelist pacing.
 */
class GamevionAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Sleep::fake();
    }

    /** @param  list<string>  $games */
    private function adapter(array $games = ['mlbb_sgmy']): GamevionAdapter
    {
        return new GamevionAdapter(baseUrl: 'https://gamevion.cloud', apiKey: 'gvk_test', games: $games);
    }

    /** @return array<string, mixed> */
    private function gvOrder(string $status, array $overrides = []): array
    {
        return array_merge([
            'order_id' => 'GVA261023-K7QX2M', 'game' => 'mlbb_sgmy', 'denom' => '86', 'order_status' => $status,
            'client_ref' => 'REF-1', 'price' => 4.2, 'currency' => 'MYR', 'message' => '',
        ], $overrides);
    }

    private function orderRequest(bool $resubmit = false): SupplierOrderRequest
    {
        return new SupplierOrderRequest(productRef: 'mlbb_sgmy:86', referenceNumber: 'REF-1', playerId: '123456789', serverId: '2001', resubmit: $resubmit);
    }

    public function test_check_balance_reads_the_myr_balance_with_the_bearer_key(): void
    {
        Http::fake(['gamevion.cloud/api/balance' => Http::response(['username' => 'My Shop', 'balance' => 50, 'currency' => 'MYR'])]);

        $result = $this->adapter()->checkBalance();

        $this->assertTrue($result->success);
        $this->assertSame(50.0, $result->data['balance']);
        $this->assertSame('My Shop', $result->data['account_name']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer gvk_test'));
    }

    public function test_list_products_keeps_gametopup_games_checkout_can_fill_and_maps_each_sku(): void
    {
        Http::fake([
            'gamevion.cloud/api/games' => Http::response([
                ['code' => 'mlbb_sgmy', 'name' => 'Mobile Legends (Malaysia)', 'kind' => 'gametopup'],
                ['code' => 'gi_glob', 'name' => 'Genshin (needs charname)', 'kind' => 'gametopup'],
                ['code' => 'steam', 'name' => 'Steam Wallet', 'kind' => 'giftcard'],
            ]),
            'gamevion.cloud/api/games/mlbb_sgmy/fields' => Http::response(['fields' => [
                ['key' => 'userid', 'required' => 1], ['key' => 'serverid', 'required' => 1],
            ]]),
            'gamevion.cloud/api/games/gi_glob/fields' => Http::response(['fields' => [
                ['key' => 'userid', 'required' => 1], ['key' => 'charname', 'required' => 1],
            ]]),
            'gamevion.cloud/api/pricelist/mlbb_sgmy' => Http::response(['game' => 'mlbb_sgmy', 'currency' => 'MYR', 'prices' => [
                ['sku' => '86', 'label' => '86 Diamonds', 'group' => 'Diamonds', 'price' => 4.2, 'available' => true],
                ['sku' => '172', 'label' => '172 Diamonds', 'group' => 'Diamonds', 'price' => 8.4, 'available' => false],
            ]]),
        ]);

        $result = $this->adapter(['mlbb_sgmy', 'gi_glob', 'steam'])->listProducts();

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->data);
        [$first, $second] = $result->data;
        $this->assertSame('mlbb_sgmy:86', $first->productRef);
        $this->assertSame(4.2, $first->price);
        $this->assertSame('MYR', $first->rawCurrency);
        $this->assertSame('active', $first->status);
        $this->assertSame('Mobile Legends (Malaysia)', $first->groupLabel);
        $this->assertSame('inactive', $second->status);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'pricelist/gi_glob') || str_contains($r->url(), 'steam'));
    }

    /** The only chosen game failing is more than a quarter: nothing may switch off. */
    public function test_list_products_fails_whole_when_its_only_game_fails(): void
    {
        Http::fake([
            'gamevion.cloud/api/games' => Http::response([['code' => 'mlbb_sgmy', 'name' => 'MLBB', 'kind' => 'gametopup']]),
            'gamevion.cloud/api/games/mlbb_sgmy/fields' => Http::response(['fields' => []]),
            'gamevion.cloud/api/pricelist/mlbb_sgmy' => Http::response(['error' => 'x', 'message' => 'Too many', 'code' => 'rate_limited'], 429),
        ]);

        $result = $this->adapter()->listProducts();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('1 of 1 chosen games (mlbb_sgmy)', $result->errorMessage);
    }

    /** @param  list<string>  $codes */
    private function fakeCatalog(array $codes, array $overrides = []): void
    {
        $fakes = ['gamevion.cloud/api/games' => Http::response(array_map(
            fn (string $code) => ['code' => $code, 'name' => strtoupper($code), 'family' => 'Fam', 'kind' => 'gametopup'],
            $codes,
        ))];

        foreach ($codes as $code) {
            $fakes["gamevion.cloud/api/games/{$code}/fields"] = Http::response(['fields' => [['key' => 'userid', 'required' => 1]]]);
            $fakes["gamevion.cloud/api/pricelist/{$code}"] = Http::response(['game' => $code, 'prices' => [
                ['sku' => '1', 'label' => '1', 'group' => 'G', 'price' => 1.0, 'available' => true],
            ]]);
        }

        Http::fake(array_merge($fakes, $overrides));
    }

    private function down(int $status = 503, string $code = 'service_unavailable'): PromiseInterface
    {
        return Http::response(['error' => 'x', 'message' => 'Service temporarily unavailable.', 'code' => $code], $status);
    }

    /** ADR-137 2026-11-05 decision 1: only the games chosen in the picker are fetched. */
    public function test_list_products_fetches_only_the_chosen_games(): void
    {
        $this->fakeCatalog(['mlbb_sgmy', 'ace_racer_id', 'hok']);

        $result = $this->adapter(['mlbb_sgmy', 'hok'])->listProducts();

        $this->assertTrue($result->success);
        $this->assertSame(['mlbb_sgmy:1', 'hok:1'], array_map(fn ($i) => $i->productRef, $result->data));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ace_racer_id'));
    }

    public function test_list_products_refuses_to_sync_when_no_game_is_chosen(): void
    {
        $this->fakeCatalog(['mlbb_sgmy']);

        $result = $this->adapter([])->listProducts();

        $this->assertFalse($result->success);
        $this->assertSame('no_games_selected', $result->errorCode);
        $this->assertStringContainsString('Middleware', $result->errorMessage);
        Http::assertNothingSent();
    }

    /** ADR-137 2026-11-05 decision 2: one game Gamevion cannot answer is left out; the rest still sync. */
    public function test_a_game_that_fails_is_skipped_and_the_rest_still_sync(): void
    {
        $this->fakeCatalog(['mlbb_sgmy', 'hok', 'valorant', 'pubgm'], ['gamevion.cloud/api/pricelist/valorant' => $this->down()]);

        $result = $this->adapter(['mlbb_sgmy', 'hok', 'valorant', 'pubgm'])->listProducts();

        $this->assertTrue($result->success);
        $this->assertSame(['mlbb_sgmy:1', 'hok:1', 'pubgm:1'], array_map(fn ($i) => $i->productRef, $result->data));
    }

    public function test_a_fields_failure_skips_the_game_too(): void
    {
        $this->fakeCatalog(['mlbb_sgmy', 'hok', 'valorant', 'pubgm'], ['gamevion.cloud/api/games/valorant/fields' => $this->down()]);

        $result = $this->adapter(['mlbb_sgmy', 'hok', 'valorant', 'pubgm'])->listProducts();

        $this->assertTrue($result->success);
        $this->assertCount(3, $result->data);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'pricelist/valorant'));
    }

    /** More than a quarter failing means Gamevion itself is down: switch nothing off. */
    public function test_the_whole_sync_fails_when_more_than_a_quarter_of_the_games_fail(): void
    {
        $this->fakeCatalog(['mlbb_sgmy', 'hok', 'valorant', 'pubgm'], [
            'gamevion.cloud/api/pricelist/valorant' => $this->down(),
            'gamevion.cloud/api/pricelist/hok' => $this->down(429, 'rate_limited'),
        ]);

        $result = $this->adapter(['mlbb_sgmy', 'hok', 'valorant', 'pubgm'])->listProducts();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('2 of 4', $result->errorMessage);
    }

    /** A bad key must never read as every game vanishing. */
    public function test_an_auth_failure_on_any_game_fails_the_whole_sync(): void
    {
        $this->fakeCatalog(['mlbb_sgmy', 'hok', 'valorant', 'pubgm'], [
            'gamevion.cloud/api/pricelist/hok' => $this->down(401, 'unauthorized'),
        ]);

        $result = $this->adapter(['mlbb_sgmy', 'hok', 'valorant', 'pubgm'])->listProducts();

        $this->assertFalse($result->success);
        $this->assertSame('unauthorized', $result->errorCode);
    }

    public function test_the_whole_sync_fails_when_the_game_list_fails(): void
    {
        Http::fake(['gamevion.cloud/api/games' => $this->down()]);

        $result = $this->adapter()->listProducts();

        $this->assertFalse($result->success);
        $this->assertSame('service_unavailable', $result->errorCode);
    }

    /** ADR-137 2026-11-05 decision 4: per-game catalog calls are logged only when they fail. */
    public function test_only_the_game_list_and_failed_per_game_calls_are_logged(): void
    {
        $this->fakeCatalog(['mlbb_sgmy', 'hok', 'valorant', 'pubgm'], ['gamevion.cloud/api/pricelist/valorant' => Http::response(['code' => 'service_unavailable', 'message' => 'x'], 503)]);

        $this->adapter(['mlbb_sgmy', 'hok', 'valorant', 'pubgm'])->listProducts();

        $logged = Queue::pushed(LogSupplierRequestJob::class)
            ->map(fn (LogSupplierRequestJob $job) => parse_url($job->entry()['url'], PHP_URL_PATH).' '.$job->entry()['outcome']);

        $this->assertSame(['/api/games success'], $logged->filter(fn ($l) => str_contains($l, 'success'))->values()->all());
        $this->assertTrue($logged->contains('/api/pricelist/valorant failure'));
        $this->assertFalse($logged->contains(fn ($l) => str_contains($l, '/fields') || str_contains($l, 'pricelist/mlbb_sgmy')));
    }

    /** The picker's source: every `gametopup` game with its family and region. */
    public function test_list_games_returns_the_gametopup_games_for_the_picker(): void
    {
        Http::fake(['gamevion.cloud/api/games' => Http::response([
            ['code' => 'mlbb_sgmy', 'name' => 'Mobile Legends (Malaysia)', 'family' => 'Mobile Legends', 'region' => 'MY', 'kind' => 'gametopup'],
            ['code' => 'steam', 'name' => 'Steam Wallet', 'family' => 'Steam', 'kind' => 'giftcard'],
        ])]);

        $result = $this->adapter()->listGames();

        $this->assertTrue($result->success);
        $this->assertSame([['code' => 'mlbb_sgmy', 'name' => 'Mobile Legends (Malaysia)', 'family' => 'Mobile Legends', 'region' => 'MY']], $result->data);
    }

    public function test_create_order_posts_the_order_with_our_reference_as_client_ref(): void
    {
        Http::fake(['gamevion.cloud/api/order' => Http::response($this->gvOrder('pending'))]);

        $result = $this->adapter()->createOrder($this->orderRequest());

        $this->assertSame(SupplierOutcome::Pending, $result->outcome);
        $this->assertSame('GVA261023-K7QX2M', $result->data['supplier_ref']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === [
            'game' => 'mlbb_sgmy', 'denom' => '86', 'userid' => '123456789', 'serverid' => '2001', 'client_ref' => 'REF-1',
        ]);
    }

    public function test_a_successful_order_carries_its_price_for_the_drawdown(): void
    {
        Http::fake(['gamevion.cloud/api/order' => Http::response($this->gvOrder('successful'))]);

        $result = $this->adapter()->createOrder($this->orderRequest());

        $this->assertSame(SupplierOutcome::Success, $result->outcome);
        $this->assertSame(4.2, $result->data['price']);
    }

    #[DataProvider('noOrderErrors')]
    public function test_an_error_where_gamevion_made_no_order_is_a_confirmed_failure(int $status, string $code): void
    {
        Http::fake(['gamevion.cloud/api/order' => Http::response(['error' => 'x', 'message' => 'm', 'code' => $code], $status)]);

        $result = $this->adapter()->createOrder($this->orderRequest());

        $this->assertSame(SupplierOutcome::Failure, $result->outcome);
        $this->assertTrue($result->outcomeConfirmedFailed);
        $this->assertFalse($result->resendUnsafeWithSameReference);
        $this->assertSame($code, $result->errorCode);
    }

    public static function noOrderErrors(): array
    {
        return [
            'insufficient balance' => [402, 'insufficient_balance'],
            'invalid player' => [422, 'invalid_player_id'],
            'not found' => [404, 'not_found'],
            'invalid request' => [400, 'invalid_request'],
        ];
    }

    #[DataProvider('unknownOutcomes')]
    public function test_an_unknown_outcome_is_never_confirmed(int $status, string $code, bool $serverError): void
    {
        Http::fake(['gamevion.cloud/api/order' => Http::response(['error' => 'x', 'message' => 'm', 'code' => $code], $status)]);

        $result = $this->adapter()->createOrder($this->orderRequest());

        $this->assertFalse($result->outcomeConfirmedFailed);
        $this->assertTrue($result->resendUnsafeWithSameReference);
        $this->assertSame($serverError, $result->isServerError);
    }

    public static function unknownOutcomes(): array
    {
        return [
            'concurrent repeat' => [409, 'request_in_progress', false],
            'rate limited' => [429, 'rate_limited', false],
            'server down' => [503, 'service_unavailable', true],
            'key revoked' => [401, 'unauthorized', true],
        ];
    }

    #[DataProvider('balanceReturnedStatuses')]
    public function test_an_order_gamevion_ended_and_refunded_is_a_confirmed_failure(string $status): void
    {
        $result = GamevionAdapter::responseFor($this->gvOrder($status));

        $this->assertSame(SupplierOutcome::Failure, $result->outcome);
        $this->assertTrue($result->outcomeConfirmedFailed);
    }

    public static function balanceReturnedStatuses(): array
    {
        return [['error'], ['cancelled'], ['refunded']];
    }

    public function test_a_resubmit_reads_first_and_never_posts_an_order_gamevion_already_has(): void
    {
        Http::fake(['gamevion.cloud/api/orders/REF-1' => Http::response($this->gvOrder('successful'))]);

        $result = $this->adapter()->createOrder($this->orderRequest(resubmit: true));

        $this->assertSame(SupplierOutcome::Success, $result->outcome);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_a_check_posts_once_with_the_same_reference_when_gamevion_never_made_the_order(): void
    {
        Http::fake([
            'gamevion.cloud/api/orders/REF-1' => Http::response(['error' => 'x', 'message' => 'Order not found', 'code' => 'not_found'], 404),
            'gamevion.cloud/api/order' => Http::response($this->gvOrder('pending')),
        ]);

        $result = $this->adapter()->checkStatus(new SupplierStatusCheckRequest(supplierRef: 'REF-1', productRef: 'mlbb_sgmy:86', playerId: '123456789', serverId: '2001'));

        $this->assertSame(SupplierOutcome::Pending, $result->outcome);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['client_ref'] === 'REF-1');
    }

    public function test_a_check_that_cannot_read_the_order_posts_nothing(): void
    {
        Http::fake(['gamevion.cloud/api/orders/REF-1' => Http::response(['error' => 'x', 'message' => 'down', 'code' => 'service_unavailable'], 503)]);

        $result = $this->adapter()->checkStatus(new SupplierStatusCheckRequest(supplierRef: 'REF-1', productRef: 'mlbb_sgmy:86', playerId: '1'));

        $this->assertFalse($result->outcomeConfirmedFailed);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_validate_player_falls_back_to_order_time_validation(): void
    {
        $this->expectException(ValidationNotSupportedException::class);

        $this->adapter()->validatePlayer('123', '2001');
    }
}
