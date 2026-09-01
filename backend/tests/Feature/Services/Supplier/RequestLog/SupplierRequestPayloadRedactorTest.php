<?php

namespace Tests\Feature\Services\Supplier\RequestLog;

use App\Services\Supplier\RequestLog\SupplierRequestPayloadRedactor;
use Tests\TestCase;

/**
 * ADR-051 decision 4 / foundation-security.md §7 — this is the actual
 * boundary enforcing "redact credentials before writing"; every case
 * here is a real secret shape one of the two live adapters sends.
 */
class SupplierRequestPayloadRedactorTest extends TestCase
{
    public function test_redacts_the_authorization_and_api_key_headers_case_insensitively(): void
    {
        $redacted = SupplierRequestPayloadRedactor::redactHeaders([
            'authorization' => ['Bearer real-token'],
            'X-Api-Key' => ['real-key'],
            'Content-Type' => ['application/json'],
        ]);

        $this->assertSame(['[REDACTED]'], $redacted['authorization']);
        $this->assertSame(['[REDACTED]'], $redacted['X-Api-Key']);
        $this->assertSame(['application/json'], $redacted['Content-Type']);
    }

    public function test_redacts_gamevion_has_no_secret_body_keys_since_its_auth_is_header_only(): void
    {
        $body = ['product_code' => 'MLBB-100', 'referenceNumber' => 'REF-1'];

        $redacted = SupplierRequestPayloadRedactor::redactBody('gamevion', $body);

        $this->assertSame($body, $redacted);
    }

    public function test_redacts_digiflazz_username_and_sign_body_keys(): void
    {
        $redacted = SupplierRequestPayloadRedactor::redactBody('digiflazz', [
            'cmd' => 'deposit',
            'username' => 'real-username',
            'sign' => 'abc123realhash',
        ]);

        $this->assertSame('deposit', $redacted['cmd']);
        $this->assertSame('[REDACTED]', $redacted['username']);
        $this->assertSame('[REDACTED]', $redacted['sign']);
    }

    public function test_passes_through_a_null_body_unchanged(): void
    {
        $this->assertNull(SupplierRequestPayloadRedactor::redactBody('gamevion', null));
    }
}
