<?php

namespace Tests\Feature\Http\Middleware;

use Tests\TestCase;

/**
 * ADR-084 PR-4 decision 7: `/docs/api` + `/docs/api.json` 301-redirect to
 * the Starlight docs site once `DOCS_SITE_URL` is set, and serve the
 * built-in Scramble UI/spec until then.
 */
class RedirectApiDocsToSiteTest extends TestCase
{
    public function test_serves_the_scramble_ui_when_no_docs_site_is_configured(): void
    {
        config(['services.docs_site.url' => null]);

        $this->get('/docs/api')->assertOk();
        $this->get('/docs/api.json')->assertOk()->assertJsonPath('openapi', '3.1.0');
    }

    public function test_redirects_the_ui_to_the_docs_site_when_configured(): void
    {
        config(['services.docs_site.url' => 'https://docs.pekangame.space']);

        $this->get('/docs/api')
            ->assertStatus(301)
            ->assertRedirect('https://docs.pekangame.space');
    }

    public function test_redirects_the_spec_to_the_static_openapi_json(): void
    {
        config(['services.docs_site.url' => 'https://docs.pekangame.space/']);

        $this->get('/docs/api.json')
            ->assertStatus(301)
            ->assertRedirect('https://docs.pekangame.space/openapi.json');
    }
}
