<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ClientErrorControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_a_valid_drift_report_and_returns_no_content(): void
    {
        Log::spy();

        $response = $this->postJson('/api/client-errors', [
            'schema' => 'CheckoutResult',
            'path' => '/api/checkout',
            'error' => 'Required field "order_number" missing',
        ]);

        $response->assertNoContent();

        Log::shouldHaveReceived('warning')->once()->with(
            'Frontend schema drift reported',
            [
                'schema' => 'CheckoutResult',
                'path' => '/api/checkout',
                'error' => 'Required field "order_number" missing',
            ],
        );
    }

    public function test_rejects_a_report_missing_required_fields(): void
    {
        $response = $this->postJson('/api/client-errors', ['schema' => 'CheckoutResult']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['path', 'error']);
    }

    public function test_requires_no_authentication(): void
    {
        $response = $this->postJson('/api/client-errors', [
            'schema' => 'CheckoutResult',
            'path' => '/api/checkout',
            'error' => 'drift',
        ]);

        $response->assertSuccessful();
    }
}
