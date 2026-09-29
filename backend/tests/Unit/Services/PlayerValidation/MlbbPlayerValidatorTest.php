<?php

namespace Tests\Unit\Services\PlayerValidation;

use App\Services\PlayerValidation\MlbbPlayerValidator;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MlbbPlayerValidatorTest extends TestCase
{
    private function fakeProvider(?PlayerValidationResult $result, ?\Throwable $throws = null): PlayerValidator
    {
        return new class($result, $throws) implements PlayerValidator
        {
            public function __construct(private readonly ?PlayerValidationResult $result, private readonly ?\Throwable $throws) {}

            public function validate(string $playerId, ?string $serverId): PlayerValidationResult
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return $this->result;
            }
        };
    }

    public function test_returns_the_first_providers_result_when_it_succeeds(): void
    {
        $first = $this->fakeProvider(PlayerValidationResult::valid('acidgameshop', 'Prime.', 'MY'));
        $second = $this->fakeProvider(PlayerValidationResult::valid('nexone', 'Prime.', 'MY'));

        $chain = new MlbbPlayerValidator([$first, $second]);
        $result = $chain->validate('51049607', '2005');

        $this->assertSame('acidgameshop', $result->provider);
    }

    public function test_falls_back_to_the_next_provider_when_the_first_is_unavailable(): void
    {
        $first = $this->fakeProvider(null, new ProviderUnavailableException('down'));
        $second = $this->fakeProvider(PlayerValidationResult::valid('nexone', 'Prime.', 'MY'));

        $chain = new MlbbPlayerValidator([$first, $second]);
        $result = $chain->validate('51049607', '2005');

        $this->assertSame('nexone', $result->provider);
    }

    public function test_does_not_fall_back_on_a_confirmed_invalid_result(): void
    {
        $first = $this->fakeProvider(PlayerValidationResult::invalid('acidgameshop'));
        $second = $this->fakeProvider(PlayerValidationResult::valid('nexone', 'Prime.', 'MY'));

        $chain = new MlbbPlayerValidator([$first, $second]);
        $result = $chain->validate('99999999', '9999');

        $this->assertFalse($result->valid);
        $this->assertSame('acidgameshop', $result->provider);
    }

    /**
     * 2026-09-29 audit K-2: the chain ran every provider to its own
     * timeout (~32s worst case), holding a php-fpm worker the whole time.
     * Once the deadline has passed, no further provider is started.
     */
    public function test_stops_falling_back_once_the_deadline_has_passed(): void
    {
        $slowDown = new class implements PlayerValidator
        {
            public function validate(string $playerId, ?string $serverId): PlayerValidationResult
            {
                Carbon::setTestNow(now()->addSeconds(9));

                throw new ProviderUnavailableException('timed out');
            }
        };
        $second = $this->fakeProvider(PlayerValidationResult::valid('nexone', 'Prime.', 'MY'));

        $chain = new MlbbPlayerValidator([$slowDown, $second], deadlineSeconds: 8);

        $this->expectException(ProviderUnavailableException::class);

        $chain->validate('51049607', '2005');
    }

    public function test_throws_when_every_provider_is_unavailable(): void
    {
        $first = $this->fakeProvider(null, new ProviderUnavailableException('down'));
        $second = $this->fakeProvider(null, new ProviderUnavailableException('down'));
        $third = $this->fakeProvider(null, new ProviderUnavailableException('down'));

        $chain = new MlbbPlayerValidator([$first, $second, $third]);

        $this->expectException(ProviderUnavailableException::class);

        $chain->validate('51049607', '2005');
    }
}
