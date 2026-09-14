<?php

namespace Tests\Feature\Services\Backup;

use App\Models\AdminUser;
use App\Services\Backup\BackupFailureAlerter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Found 2026-09-14: every production backup restore-test failed since
 * go-live (2026-09-02), and no admin ever saw an alert about it because
 * `MAIL_MAILER` was `log` in production — `Mail::` silently wrote to
 * the log instead of sending. This alerter is now routed through
 * `PlunkMailer` instead (already proven live for membership OTP), so
 * these tests prove an actual send is attempted, not just that
 * something was logged.
 */
class BackupFailureAlerterTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerts_every_active_admin_via_plunk(): void
    {
        Http::fake(['next-api.useplunk.com/*' => Http::response(['success' => true], 200)]);

        AdminUser::factory()->create(['email' => 'active-one@example.com', 'is_active' => true]);
        AdminUser::factory()->create(['email' => 'active-two@example.com', 'is_active' => true]);
        AdminUser::factory()->create(['email' => 'inactive@example.com', 'is_active' => false]);

        app(BackupFailureAlerter::class)->alert('Backup run failed', 'something broke');

        Http::assertSent(fn ($request) => $request['to'] === 'active-one@example.com');
        Http::assertSent(fn ($request) => $request['to'] === 'active-two@example.com');
        Http::assertNotSent(fn ($request) => $request['to'] === 'inactive@example.com');
    }

    public function test_one_recipients_send_failure_does_not_block_the_others(): void
    {
        AdminUser::factory()->create(['email' => 'first@example.com', 'is_active' => true]);
        AdminUser::factory()->create(['email' => 'second@example.com', 'is_active' => true]);

        Http::fake([
            'next-api.useplunk.com/*' => Http::sequence()
                ->push(['error' => 'bounced'], 422)
                ->push(['success' => true], 200),
        ]);

        Log::spy();

        app(BackupFailureAlerter::class)->alert('Backup run failed', 'something broke');

        Http::assertSentCount(2);
        Log::shouldHaveReceived('critical')
            ->withArgs(fn (string $message) => str_contains($message, 'failed to send failure alert to first@example.com'))
            ->once();
    }

    public function test_logs_critical_regardless_of_recipients(): void
    {
        Http::fake(['next-api.useplunk.com/*' => Http::response(['success' => true], 200)]);
        Log::spy();

        app(BackupFailureAlerter::class)->alert('Backup run failed', 'something broke');

        Log::shouldHaveReceived('critical')
            ->withArgs(fn (string $message) => str_contains($message, '[Backup] Backup run failed: something broke'))
            ->once();
    }
}
