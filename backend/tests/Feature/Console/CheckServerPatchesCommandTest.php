<?php

namespace Tests\Feature\Console;

use App\Services\Backup\BackupFailureAlerter;
use App\Services\Ops\ServerPatchCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * ADR-145 decision 3: a daily email to every admin when production runs a
 * PHP older than php.net's latest release of its branch, or the box waits
 * for a reboot; once when it appears, again every 7 days while it stays.
 */
class CheckServerPatchesCommandTest extends TestCase
{
    private string $rebootFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(BackupFailureAlerter::class, Mockery::spy(BackupFailureAlerter::class));
        $this->rebootFile = tempnam(sys_get_temp_dir(), 'reboot');
        unlink($this->rebootFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->rebootFile);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runningPhp(string $version, bool $phpNetUp = true): void
    {
        Http::fake(['www.php.net/releases/*' => $phpNetUp
            ? Http::response(['version' => '8.4.28', 'date' => '19 Nov 2026', 'tags' => ['security']])
            : Http::response('oops', 503)]);
        $this->app->instance(ServerPatchCheck::class, new ServerPatchCheck($version, $this->rebootFile));
    }

    private function alerter(): Mockery\MockInterface
    {
        return app(BackupFailureAlerter::class);
    }

    public function test_a_php_behind_its_branch_is_emailed_once_then_reminded_after_seven_days(): void
    {
        $this->runningPhp('8.4.27');

        $this->artisan('app:check-server-patches')->assertSuccessful();
        $this->artisan('app:check-server-patches')->assertSuccessful();
        $this->alerter()->shouldHaveReceived('alert')
            ->with('PHP 8.4.27 is behind 8.4.28', Mockery::on(fn (string $m) => str_contains($m, '19 Nov 2026') && str_contains($m, 'security')), 'Server')
            ->once();

        Carbon::setTestNow(now()->addDays(7));
        $this->artisan('app:check-server-patches')->assertSuccessful();
        $this->alerter()->shouldHaveReceived('alert')->with('PHP 8.4.27 is behind 8.4.28', Mockery::any(), 'Server')->twice();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'version=8.4'));
    }

    public function test_the_latest_php_and_no_pending_reboot_send_nothing(): void
    {
        $this->runningPhp('8.4.28');

        $this->artisan('app:check-server-patches')->assertSuccessful();

        $this->alerter()->shouldNotHaveReceived('alert');
    }

    public function test_a_pending_reboot_is_emailed_with_the_packages_that_need_it(): void
    {
        $this->runningPhp('8.4.28');
        file_put_contents($this->rebootFile, "*** System restart required ***\n");
        file_put_contents($this->rebootFile.'.pkgs', "libc6\nlinux-image-6.8.0-130-generic\nlibc6\n");

        $this->artisan('app:check-server-patches')->assertSuccessful();

        $this->alerter()->shouldHaveReceived('alert')
            ->with('The server needs a reboot', Mockery::on(fn (string $m) => str_contains($m, ': libc6, linux-image-6.8.0-130-generic.')), 'Server')
            ->once();
        @unlink($this->rebootFile.'.pkgs');
    }

    public function test_a_resolved_problem_that_returns_is_emailed_again_at_once(): void
    {
        $this->runningPhp('8.4.28');
        touch($this->rebootFile);
        $this->artisan('app:check-server-patches')->assertSuccessful();

        unlink($this->rebootFile);
        $this->artisan('app:check-server-patches')->assertSuccessful();
        touch($this->rebootFile);
        $this->artisan('app:check-server-patches')->assertSuccessful();

        $this->alerter()->shouldHaveReceived('alert')->with('The server needs a reboot', Mockery::any(), 'Server')->twice();
    }

    public function test_php_net_unreachable_is_logged_not_emailed(): void
    {
        $this->runningPhp('8.4.27', phpNetUp: false);

        $this->artisan('app:check-server-patches')->assertSuccessful();

        $this->alerter()->shouldNotHaveReceived('alert');
    }
}
