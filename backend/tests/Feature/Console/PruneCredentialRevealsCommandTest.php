<?php

namespace Tests\Feature\Console;

use App\Models\CredentialReveal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-124 decision 11: credential_reveals is kept one year. */
class PruneCredentialRevealsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_only_rows_older_than_a_year(): void
    {
        $row = fn ($at) => CredentialReveal::query()->create(['actor_type' => 'admin', 'actor_id' => 1, 'action' => 'reveal', 'field_key' => 'password', 'created_at' => $at]);
        $old = $row(now()->subDays(366));
        $recent = $row(now()->subDays(364));

        $this->artisan('app:prune-credential-reveals')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }
}
