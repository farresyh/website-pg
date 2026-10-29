<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Order;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\Support\CheckoutControllerFixtures;
use Tests\TestCase;

/** ADR-130: the bank the customer picked is applied to the checkout URL in the response, and nowhere else. */
class CheckoutControllerFpxBankTest extends TestCase
{
    use CheckoutControllerFixtures;
    use RefreshDatabase;

    public const URL = 'https://gate.chip-in.asia/p/pr-bank-test/';

    private int $createCalls = 0;

    private function bindChipShapedGateway(): void
    {
        $test = $this;
        $gateway = new class($test) implements PaymentGateway
        {
            public function __construct(private readonly CheckoutControllerFpxBankTest $test) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                $this->test->recordCreate($request);

                return PaymentResponse::success(['payment_request_id' => 'pr-bank-test']);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success([
                    'payment_request_id' => $paymentRequestId,
                    // The ChipGateway shape: what getPayment really returns, plain URL.
                    'actions' => [['type' => 'REDIRECT', 'descriptor' => 'WEB_URL', 'value' => CheckoutControllerFpxBankTest::URL]],
                ]);
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used');
            }
        };
        $this->app->bind('payment-gateway.chip', fn () => $gateway);
        $this->activeChannel('fpx');
    }

    public function recordCreate(PaymentRequest $request): void
    {
        $this->createCalls++;
        // The bank is never sent to CHIP: nothing about it is on the purchase.
        $this->assertSame([], array_diff_key($request->channelProperties, ['success_return_url' => 1, 'failure_return_url' => 1]));
    }

    public function test_a_chosen_bank_goes_straight_to_the_bank(): void
    {
        $this->bindChipShapedGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['channel_code' => 'fpx', 'fpx_bank_code' => 'MB2U0227']));

        $response->assertCreated();
        $response->assertJsonPath('payment_actions.0.value', self::URL.'?preferred=fpx&fpx_bank_code=MB2U0227');
    }

    public function test_no_bank_returns_the_plain_url(): void
    {
        $this->bindChipShapedGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['channel_code' => 'fpx']));

        $response->assertCreated();
        $response->assertJsonPath('payment_actions.0.value', self::URL);
    }

    public function test_a_replay_with_another_bank_is_the_same_order_and_gets_that_banks_url(): void
    {
        $this->bindChipShapedGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $payload = $this->payload($game, $package, ['channel_code' => 'fpx', 'fpx_bank_code' => 'MB2U0227']);

        $this->postJson('/api/checkout', $payload)->assertCreated();
        $replay = $this->postJson('/api/checkout', [...$payload, 'fpx_bank_code' => 'BCBB0235']);

        $replay->assertOk();
        $replay->assertJsonPath('payment_actions.0.value', self::URL.'?preferred=fpx&fpx_bank_code=BCBB0235');
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, $this->createCalls);
    }

    public function test_a_replay_that_switches_to_the_other_bank_link_gets_the_plain_url(): void
    {
        $this->bindChipShapedGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $payload = $this->payload($game, $package, ['channel_code' => 'fpx', 'fpx_bank_code' => 'MB2U0227']);

        $this->postJson('/api/checkout', $payload)->assertCreated();
        $replay = $this->postJson('/api/checkout', collect($payload)->except('fpx_bank_code')->all());

        $replay->assertOk();
        $replay->assertJsonPath('payment_actions.0.value', self::URL);
    }

    public function test_the_stored_payment_ref_and_channel_carry_no_bank(): void
    {
        $this->bindChipShapedGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package, ['channel_code' => 'fpx', 'fpx_bank_code' => 'MB2U0227']))->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('pr-bank-test', $order->payment_ref);
        $this->assertSame('fpx', $order->channel_code);
    }

    public function test_an_unlisted_bank_code_is_a_validation_error(): void
    {
        $this->bindChipShapedGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['channel_code' => 'fpx', 'fpx_bank_code' => 'XXX9999']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('fpx_bank_code');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_bank_is_refused_on_any_other_channel(): void
    {
        $this->bindChipShapedGateway();
        $this->activeChannel('fpx_b2b1', ['method_key' => 'fpx_chip_b2b1']);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['channel_code' => 'fpx_b2b1', 'fpx_bank_code' => 'MB2U0227']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('fpx_bank_code');
    }
}
