<?php

namespace Tests\Feature\Auth;

use App\Models\AdminUser;
use App\Models\Supplier;
use App\Services\Backup\BackupFailureAlerter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** ADR-121 decision 5: step-up on the five dangerous actions. */
class AdminMfaStepUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost:3000/');
        $this->app->instance(BackupFailureAlerter::class, Mockery::spy(BackupFailureAlerter::class));
    }

    /**
     * The pinned set. A new dangerous route must be added here on purpose;
     * removing the middleware from one of these fails too.
     */
    public function test_exactly_these_routes_require_step_up(): void
    {
        $guarded = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'mfa.stepup')))
            ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri().' '.collect($r->gatherMiddleware())->first(fn ($m) => str_starts_with($m, 'mfa.stepup')))
            ->sort()->values()->all();

        $this->assertSame([
            'PATCH api/admin-users/{admin_user}/status mfa.stepup',
            'PATCH api/withdrawals/{withdrawal}/approve mfa.stepup',
            'POST api/admin-users mfa.stepup',
            'POST api/mfa/recovery-codes mfa.stepup',
            'POST api/middleware/suppliers mfa.stepup',
            'POST api/resellers/{reseller}/wallet/credit mfa.stepup',
            'PUT api/admin-users/{admin_user} mfa.stepup',
            'PUT api/middleware/payment-gateways/{gatewayKey} mfa.stepup',
            'PUT api/middleware/suppliers/{supplier} mfa.stepup:api_config',
        ], $guarded);
    }

    private function signIn(array $attributes = []): AdminUser
    {
        $admin = AdminUser::factory()->superAdmin()->create(['password' => 'secret-password'] + $attributes);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'secret-password'])->assertOk();

        if ($admin->mfa_enabled) {
            $this->postJson('/api/login/mfa', ['code' => $this->totp($admin)])->assertOk();
        }

        return $admin;
    }

    private function mfaAttributes(): array
    {
        return ['mfa_required' => true, 'mfa_enabled' => true, 'mfa_secret' => (new Google2FA)->generateSecretKey(32)];
    }

    private function totp(AdminUser $admin, int $stepOffset = 0): string
    {
        $g = new Google2FA;

        return $g->oathTotp($admin->refresh()->mfa_secret, $g->getTimestamp() + $stepOffset);
    }

    public function test_an_admin_without_mfa_is_refused_a_dangerous_action(): void
    {
        $this->signIn();

        $this->postJson('/api/mfa/recovery-codes')
            ->assertForbidden()
            ->assertJsonPath('code', 'mfa_not_enabled');
    }

    public function test_an_admin_with_mfa_must_step_up_and_it_lasts_five_minutes(): void
    {
        $admin = $this->signIn($this->mfaAttributes());

        $this->postJson('/api/mfa/recovery-codes')->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');

        $this->postJson('/api/mfa/step-up', ['code' => '000000'])->assertUnprocessable();
        // The login code was step 0; the next step's code is new.
        $this->postJson('/api/mfa/step-up', ['code' => $this->totp($admin, 1)])->assertOk();

        $this->postJson('/api/mfa/recovery-codes')->assertOk()->assertJsonCount(8, 'recovery_codes');

        $this->travel(301)->seconds();
        $this->postJson('/api/mfa/recovery-codes')->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
    }

    public function test_a_supplier_edit_needs_step_up_only_when_it_changes_credentials(): void
    {
        $this->signIn();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'currency' => 'MYR', 'api_config' => ['api_key' => 'old']]);

        $this->putJson("/api/middleware/suppliers/{$supplier->id}", ['name' => 'Renamed'])
            ->assertJsonMissing(['code' => 'mfa_not_enabled']);

        $this->putJson("/api/middleware/suppliers/{$supplier->id}", ['api_config' => ['key' => 'x']])
            ->assertForbidden()
            ->assertJsonPath('code', 'mfa_not_enabled');
    }

    public function test_require_mfa_is_one_way_and_needs_no_step_up(): void
    {
        $this->signIn();
        $other = AdminUser::factory()->create();

        $this->patchJson("/api/admin-users/{$other->id}/require-mfa")
            ->assertOk()
            ->assertJsonPath('mfa_status', 'pending');

        $this->assertTrue($other->refresh()->mfa_required);
    }

    public function test_a_password_change_ends_the_admins_other_sessions(): void
    {
        $admin = $this->signIn($this->mfaAttributes());
        $other = AdminUser::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'other-a', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'mine', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->postJson('/api/mfa/step-up', ['code' => $this->totp($admin, 1)])->assertOk();
        $this->putJson("/api/admin-users/{$other->id}", ['name' => $other->name, 'email' => $other->email, 'role' => 'admin', 'password' => 'a-new-password-1'])->assertOk();

        $this->assertSame(['mine'], DB::table('sessions')->pluck('id')->all());
    }

    /**
     * Found live 2026-10-14: saving your own account with a password (the
     * browser had autofilled "New Password") logged you out on the next
     * request — Sanctum's AuthenticateSession still held the old password's
     * hash. Your own session must survive; only other sessions end.
     */
    public function test_changing_your_own_password_keeps_your_session(): void
    {
        $admin = $this->signIn($this->mfaAttributes());
        $this->postJson('/api/mfa/step-up', ['code' => $this->totp($admin, 1)])->assertOk();

        $this->putJson("/api/admin-users/{$admin->id}", ['name' => 'Renamed', 'email' => $admin->email, 'role' => 'super_admin', 'password' => 'a-new-password-1'])->assertOk();
        // The test app keeps resolved guards between requests; production starts fresh.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/me')->assertOk()->assertJsonPath('name', 'Renamed');
    }

    public function test_the_reset_command_clears_mfa_ends_sessions_and_alerts(): void
    {
        $admin = AdminUser::factory()->create($this->mfaAttributes());
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);

        $this->artisan('admin:mfa-reset', ['email' => $admin->email])->assertSuccessful();

        $admin->refresh();
        $this->assertSame('none', $admin->mfa_status);
        $this->assertNull($admin->mfa_secret);
        $this->assertSame(0, DB::table('sessions')->count());
        app(BackupFailureAlerter::class)->shouldHaveReceived('alert')->with('MFA reset over SSH', Mockery::any(), 'Security');
    }
}
