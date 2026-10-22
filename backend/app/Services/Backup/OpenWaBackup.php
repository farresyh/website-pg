<?php

namespace App\Services\Backup;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * ADR-039 2026-10-22 addendum: the OpenWA source of the one backup system.
 *
 * OpenWA's own scripts/backup.sh makes the archive (a consistent SQLite
 * snapshot plus Baileys auth, plugins and generated config), so what to
 * back up stays right across OpenWA upgrades. This class adds OpenWA's
 * `./.env`, encrypts both into one AES-256 zip, uploads it, and restore-tests
 * it through OpenWA's own scripts/restore.sh — the path a real restore uses.
 *
 * Both scripts run with a clean environment (`env -i`): they read their
 * settings from the environment before `./.env`, and Laravel's own env vars
 * must never steer which files they read or write.
 */
class OpenWaBackup
{
    public function __construct(private readonly BackupRestorer $restorer) {}

    /**
     * @return array{path: string, size_bytes: int}
     */
    public function create(string $disk, string $workDir): array
    {
        $installDir = $this->installDir();
        $password = BackupArchivePassword::require();
        $this->makeDir($workDir);

        $this->runScript($installDir, ['BACKUP_DIR' => $workDir], ['scripts/backup.sh']);

        $tars = glob($workDir.'/openwa-backup-*.tar.gz') ?: [];
        if (count($tars) !== 1) {
            throw new RuntimeException('OpenWA backup.sh succeeded but left '.count($tars).' archives instead of one');
        }

        $envFile = $installDir.'/.env';
        if (! is_file($envFile)) {
            throw new RuntimeException("OpenWA .env not found at [{$envFile}]");
        }

        $zipPath = $workDir.'/openwa.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('could not create the OpenWA backup zip');
        }
        $members = [basename($tars[0]) => $tars[0], 'openwa.env' => $envFile];
        foreach ($members as $name => $file) {
            $zip->addFile($file, $name);
            if ($password !== null) {
                $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
            }
        }
        if (! $zip->close()) {
            throw new RuntimeException('could not write the OpenWA backup zip');
        }

        $path = config('backup.openwa.folder').'/openwa-'.now()->format('Y-m-d-H-i-s').'.zip';
        $stream = fopen($zipPath, 'rb');
        try {
            if (Storage::disk($disk)->writeStream($path, $stream) !== true) {
                throw new RuntimeException("could not upload the OpenWA backup to [{$disk}]");
            }
        } finally {
            fclose($stream);
        }

        return ['path' => $path, 'size_bytes' => (int) filesize($zipPath)];
    }

    /**
     * Download → decrypt → OpenWA's restore.sh into `<workDir>/restored/data`
     * → check what came back.
     *
     * @return array{passed: bool, details: array<string, mixed>}
     */
    public function restoreTest(string $disk, string $path, string $workDir): array
    {
        try {
            $this->makeDir($workDir);
            $extracted = $this->restorer->extractOpenWa($this->restorer->download($disk, $path, $workDir), $workDir);
            $dataDir = $workDir.'/restored/data';
            $this->runScript($this->installDir(), ['OPENWA_DATA_DIR' => $dataDir], ['scripts/restore.sh', $extracted['tar']]);

            return $this->verify($dataDir, $extracted['env']);
        } catch (Throwable $e) {
            return ['passed' => false, 'details' => ['error' => $e->getMessage()]];
        }
    }

    /**
     * Flat window (decision Q4): archives older than `keep_days` go. Only
     * called after a run passed its restore test, so failing runs never
     * thin out the last good copies.
     *
     * @return list<string> deleted paths
     */
    public function prune(string $disk): array
    {
        $cutoff = now()->subDays((int) config('backup.openwa.keep_days'))->getTimestamp();
        $storage = Storage::disk($disk);

        $old = collect($storage->files(config('backup.openwa.folder')))
            ->filter(fn (string $file) => str_ends_with($file, '.zip') && $storage->lastModified($file) < $cutoff)
            ->values()
            ->all();

        if ($old !== []) {
            $storage->delete($old);
        }

        return $old;
    }

    /**
     * @return array{passed: bool, details: array<string, mixed>}
     */
    private function verify(string $dataDir, ?string $envFile): array
    {
        $main = $this->sqlite($dataDir.'/main.sqlite');
        $data = $this->sqlite($dataDir.'/openwa.sqlite');

        try {
            $sessionIds = $data->query('SELECT id FROM sessions')->fetchAll(PDO::FETCH_COLUMN);
            $expected = array_values(array_filter([config('services.openwa.session_id'), config('services.openwa.cs_session_id')]));

            $details = [
                'main_integrity' => $main->query('PRAGMA integrity_check')->fetchColumn(),
                'data_integrity' => $data->query('PRAGMA integrity_check')->fetchColumn(),
                'api_keys' => (int) $main->query('SELECT count(*) FROM api_keys')->fetchColumn(),
                'sessions' => count($sessionIds),
                'sessions_missing' => array_values(array_diff($expected, $sessionIds)),
                'messages' => (int) $data->query('SELECT count(*) FROM messages')->fetchColumn(),
            ];
        } finally {
            $main = $data = null;
        }

        $baileys = $this->checkBaileys($dataDir.'/baileys', $expected);
        $details += $baileys + ['env_included' => $envFile !== null];

        $passed = $details['main_integrity'] === 'ok'
            && $details['data_integrity'] === 'ok'
            && $details['api_keys'] > 0
            && $details['sessions_missing'] === []
            && $baileys['baileys_unreadable'] === []
            && $baileys['creds_missing'] === []
            && $envFile !== null;

        return ['passed' => $passed, 'details' => $details];
    }

    /**
     * backup.sh copies Baileys state while the gateway runs, so a file can be
     * torn mid-write (ENGINE-STATE-NOTE). Every file must still parse, and
     * each configured session must have its creds.json. A set that parses
     * but is inconsistent across files isn't detectable here; the 14 daily
     * copies, and in the worst case a QR re-pair, cover that.
     *
     * @param  list<string>  $sessionIds
     * @return array{baileys_files: int, baileys_unreadable: list<string>, creds_missing: list<string>}
     */
    private function checkBaileys(string $dir, array $sessionIds): array
    {
        $files = is_dir($dir) ? glob($dir.'/*/*.json') ?: [] : [];
        $unreadable = array_values(array_filter($files, fn (string $file) => ! json_validate((string) file_get_contents($file))));

        return [
            'baileys_files' => count($files),
            'baileys_unreadable' => array_slice(array_map(fn (string $file) => substr($file, strlen($dir) + 1), $unreadable), 0, 10),
            'creds_missing' => array_values(array_filter($sessionIds, fn (string $id) => ! is_file("{$dir}/{$id}/creds.json"))),
        ];
    }

    private function sqlite(string $file): PDO
    {
        if (! is_file($file)) {
            throw new RuntimeException('restore left no '.basename($file));
        }

        return new PDO('sqlite:'.$file, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * @param  array<string, string>  $env
     * @param  list<string>  $command
     */
    private function runScript(string $installDir, array $env, array $command): ProcessResult
    {
        $clean = ['PATH='.(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'), 'HOME='.(getenv('HOME') ?: $installDir)];
        foreach ($env as $key => $value) {
            $clean[] = "{$key}={$value}";
        }

        $result = Process::path($installDir)
            ->timeout((int) config('backup.openwa.script_timeout_seconds'))
            ->run(['env', '-i', ...$clean, 'bash', ...$command]);

        if ($result->failed()) {
            $output = trim($result->errorOutput()."\n".$result->output());
            throw new RuntimeException('OpenWA '.basename($command[0]).' failed (exit '.$result->exitCode().'): '.mb_substr($output, -1000));
        }

        return $result;
    }

    private function makeDir(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new RuntimeException("could not create [{$dir}]");
        }
    }

    private function installDir(): string
    {
        $dir = rtrim((string) config('backup.openwa.install_dir'), '/');

        if ($dir === '' || ! is_file($dir.'/scripts/backup.sh')) {
            throw new RuntimeException("OPENWA_INSTALL_DIR [{$dir}] is not an OpenWA install (no scripts/backup.sh)");
        }

        return $dir;
    }
}
