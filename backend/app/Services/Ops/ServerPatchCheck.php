<?php

namespace App\Services\Ops;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ADR-145 decision 3: what the box is waiting for. unattended-upgrades
 * covers Ubuntu's own security pocket only; PHP (Ondřej Surý's repository), nginx,
 * Redis and Node come from repositories it never touches, and a kernel
 * fix waits for a reboot. Nothing told us either (the 2026-11-19 PHP
 * security release reached us through another company).
 *
 * The PHP compared is this process's (the CLI); FPM runs the same
 * packages and the patch window ends in a reboot, so they don't diverge.
 */
final class ServerPatchCheck
{
    public const REBOOT_FILE = '/var/run/reboot-required';

    public function __construct(
        private readonly string $phpVersion = PHP_VERSION,
        private readonly string $rebootFile = self::REBOOT_FILE,
    ) {}

    /**
     * Each open problem, keyed so a changed one (a newer PHP) reads as new.
     *
     * @return list<array{key: string, subject: string, message: string}>
     */
    public function problems(): array
    {
        $problems = [];
        $latest = $this->latestPhp();

        if ($latest !== null && version_compare($this->phpVersion, $latest['version'], '<')) {
            $problems[] = [
                'key' => "php:{$latest['version']}",
                'subject' => "PHP {$this->phpVersion} is behind {$latest['version']}",
                'message' => sprintf(
                    'Production runs PHP %s. php.net released %s on %s%s. Once `apt-cache policy php%s-fpm` on the box shows it (the package repository follows php.net by a day or a few), run the patch window in ADR-145: a full upgrade, then a reboot, then the health checks.',
                    $this->phpVersion, $latest['version'], $latest['date'], $latest['tags'] === [] ? '' : ' ('.implode(', ', $latest['tags']).')', self::branch($this->phpVersion),
                ),
            ];
        }

        if (is_file($this->rebootFile)) {
            // The .pkgs file appends per install, so a package can repeat.
            $packages = is_file($this->rebootFile.'.pkgs') ? implode(', ', array_unique(array_filter(array_map('trim', explode("\n", (string) file_get_contents($this->rebootFile.'.pkgs')))))) : '';
            $problems[] = [
                'key' => 'reboot',
                'subject' => 'The server needs a reboot',
                'message' => 'Installed updates take effect only after a reboot'.($packages === '' ? '.' : ": {$packages}.")
                    .' Reboot in the next patch window (ADR-145), at a quiet hour, then run the health checks.',
            ];
        }

        return $problems;
    }

    /** "8.4.27" → "8.4" */
    private static function branch(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    /** @return array{version: string, date: string, tags: list<string>}|null null when php.net can't say */
    private function latestPhp(): ?array
    {
        $branch = self::branch($this->phpVersion);

        try {
            $response = Http::timeout(15)->acceptJson()->get('https://www.php.net/releases/', ['json' => '', 'version' => $branch]);
        } catch (ConnectionException $e) {
            Log::warning("[Server] php.net releases unreachable: {$e->getMessage()}");

            return null;
        }

        $version = $response->successful() ? $response->json('version') : null;

        if (! is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            Log::warning("[Server] php.net releases gave no version for {$branch} (HTTP {$response->status()}).");

            return null;
        }

        return ['version' => $version, 'date' => (string) $response->json('date', 'an unknown date'), 'tags' => array_values(array_filter((array) $response->json('tags', []), 'is_string'))];
    }
}
