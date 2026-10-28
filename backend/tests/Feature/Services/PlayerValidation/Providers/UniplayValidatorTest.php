<?php

namespace Tests\Feature\Services\PlayerValidation\Providers;

use App\Services\PlayerValidation\Providers\UniplayValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UniplayValidatorTest extends TestCase
{
    private const PAGE = '<script>headers: { "Authorization": "Basic dG9rMQ==" }</script>';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(UniplayValidator::TOKEN_CACHE_KEY);
    }

    private function validator(): UniplayValidator
    {
        return new UniplayValidator(baseUrl: 'https://uniplay.id');
    }

    public function test_normalizes_a_valid_response_and_sends_the_page_token(): void
    {
        Http::fake([
            'uniplay.id/cek-region' => Http::response(self::PAGE),
            'uniplay.id/ajax/check-region.php' => Http::response(['status' => true, 'nickname' => 'Prime.', 'region' => 'MY', 'user_id' => '51049607', 'server' => '2005']),
        ]);

        $result = $this->validator()->validate('51049607', '2005');

        $this->assertTrue($result->valid);
        $this->assertSame('Prime.', $result->nickname);
        $this->assertSame('MY', $result->countryCode);
        $this->assertSame('uniplay', $result->provider);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/ajax/check-region.php')
            && $r->header('Authorization') === ['Basic dG9rMQ==']
            && $r['user_id'] === '51049607' && $r['server_id'] === '2005');
    }

    public function test_status_false_is_an_invalid_id(): void
    {
        Http::fake([
            'uniplay.id/cek-region' => Http::response(self::PAGE),
            'uniplay.id/ajax/check-region.php' => Http::response(['status' => false, 'message' => 'x']),
        ]);

        $this->assertFalse($this->validator()->validate('1', '1')->valid);
    }

    public function test_caches_the_token_and_renews_it_once_after_a_rejection(): void
    {
        Cache::put(UniplayValidator::TOKEN_CACHE_KEY, 'Basic c3RhbGU=', 3600);
        Http::fake([
            'uniplay.id/cek-region' => Http::response(self::PAGE),
            'uniplay.id/ajax/check-region.php' => Http::sequence()
                ->push('denied', 401)
                ->push(['status' => true, 'nickname' => 'Prime.', 'region' => 'MY']),
        ]);

        $this->assertTrue($this->validator()->validate('51049607', '2005')->valid);
        $this->assertSame('Basic dG9rMQ==', Cache::get(UniplayValidator::TOKEN_CACHE_KEY));
    }

    public function test_a_page_without_a_token_is_unavailable(): void
    {
        Http::fake(['uniplay.id/cek-region' => Http::response('<html></html>')]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('1', '1');
    }

    public function test_a_server_error_is_unavailable_not_invalid(): void
    {
        Http::fake([
            'uniplay.id/cek-region' => Http::response(self::PAGE),
            'uniplay.id/ajax/check-region.php' => Http::response('boom', 500),
        ]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('1', '1');
    }
}
