<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * ADR-074 decision 3: the Scramble reference at `/docs/api` is deliberately
 * public — handed to an external reseller's dev team. Scramble's
 * `RestrictedDocsAccess` middleware allows the request when
 * `Gate::allows('viewApiDocs')`, evaluated with NO authenticated user; a
 * zero-parameter gate closure fails that guest check and 403s everyone
 * (which is what happened in production). These tests pin the gate as
 * guest-reachable. `APP_ENV=testing` here, so the middleware's
 * `environment('local')` short-circuit does not apply — the gate is
 * genuinely exercised.
 */
class ApiDocsAccessTest extends TestCase
{
    public function test_the_openapi_reference_ui_is_reachable_by_a_guest(): void
    {
        $this->get('/docs/api')->assertOk();
    }

    public function test_the_openapi_document_is_reachable_by_a_guest(): void
    {
        $this->get('/docs/api.json')
            ->assertOk()
            ->assertHeader('content-type', 'application/json');
    }
}
