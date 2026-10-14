<?php

namespace App\Services\Auth;

use App\Models\AdminUser;
use App\Services\Backup\BackupFailureAlerter;
use App\Support\AccountLoginThrottle;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * ADR-121: the one place that reads or writes an admin's TOTP secret and
 * recovery codes. Controllers, middleware and the reset command call this;
 * nothing else touches `mfa_secret`.
 */
class AdminMfa
{
    private const RECOVERY_CODE_COUNT = 8;

    private const WINDOW = 1; // ±1 30-second step

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly BackupFailureAlerter $alerter,
    ) {}

    /**
     * Setup data for an account awaiting setup. The secret is kept once
     * generated, so reloading the setup screen mid-scan keeps the same QR.
     *
     * @return array{secret: string, qr_svg: string}
     */
    public function setupData(AdminUser $admin): array
    {
        if (! $admin->mfa_secret) {
            $admin->forceFill(['mfa_secret' => $this->google2fa->generateSecretKey(32)])->save();
        }

        $url = $this->google2fa->getQRCodeUrl('PekanGame Admin', $admin->email, $admin->mfa_secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);

        return ['secret' => $admin->mfa_secret, 'qr_svg' => $svg];
    }

    /**
     * Confirms setup with one TOTP code (a recovery code doesn't exist yet).
     *
     * @return list<string>|null the plain recovery codes, shown once; null on a wrong code
     */
    public function confirmSetup(AdminUser $admin, string $code): ?array
    {
        if ($admin->mfa_enabled || ! $admin->mfa_secret || ! $this->verifyTotp($admin, $code)) {
            return null;
        }

        $admin->forceFill(['mfa_enabled' => true])->save();

        return $this->regenerateRecoveryCodes($admin);
    }

    /** A TOTP code, or else one single-use recovery code (alerting on use). */
    public function verify(AdminUser $admin, string $code): bool
    {
        if (! $admin->mfa_enabled) {
            return false;
        }

        $code = trim($code);

        if (preg_match('/^\d{6}$/', $code)) {
            return $this->verifyTotp($admin, $code);
        }

        return $this->consumeRecoveryCode($admin, $code);
    }

    /** @return list<string> plain codes; every older code stops working */
    public function regenerateRecoveryCodes(AdminUser $admin): array
    {
        $codes = collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => $this->randomChunk().'-'.$this->randomChunk())
            ->all();

        $admin->forceFill(['mfa_recovery_codes' => array_map($this->hash(...), $codes)])->save();

        return $codes;
    }

    /** The SSH reset (decision 7): clears MFA entirely and ends every session. */
    public function reset(AdminUser $admin): void
    {
        $admin->forceFill([
            'mfa_secret' => null,
            'mfa_enabled' => false,
            'mfa_required' => false,
            'mfa_recovery_codes' => null,
            'mfa_last_timestep' => null,
        ])->save();

        $this->endSessions($admin);
        $this->alert($admin, 'MFA reset over SSH', "MFA for {$admin->email} was reset with `php artisan admin:mfa-reset`. Every session of this admin was ended.");
    }

    /** Deletes the admin's database sessions, optionally keeping the current one. */
    public function endSessions(AdminUser $admin, ?string $exceptSessionId = null): void
    {
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $admin->getKey())
            ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->delete();
    }

    /** A wrong code at login or step-up: counts toward the account lockout, alerting when it locks. */
    public function recordFailedCode(AdminUser $admin, AccountLoginThrottle $throttle, ?string $ip): void
    {
        Log::warning('Admin MFA code rejected', ['admin_id' => $admin->id, 'ip' => $ip]);

        if ($throttle->recordFailure()) {
            $this->alert($admin, 'Account locked', "{$admin->email} was locked for 15 minutes after too many wrong MFA codes (last from IP {$ip}).");
        }
    }

    public function alert(AdminUser $admin, string $context, string $message): void
    {
        $this->alerter->alert($context, $message, 'Security');
    }

    private function verifyTotp(AdminUser $admin, string $code): bool
    {
        // verifyKeyNewer refuses any time-step at or before the last accepted
        // one, so a code can never be used twice (replay guard). Always pass
        // an int: with null the library returns `true` instead of the
        // matched step, and we'd store the wrong one.
        // ponytail: read-then-write, so two requests racing the same code in
        // the same instant could both pass; lockForUpdate if admins multiply.
        $timestep = $this->google2fa->verifyKeyNewer($admin->mfa_secret, $code, $admin->mfa_last_timestep ?? 0, self::WINDOW);

        if ($timestep === false) {
            return false;
        }

        $admin->forceFill(['mfa_last_timestep' => $timestep])->save();

        return true;
    }

    private function consumeRecoveryCode(AdminUser $admin, string $code): bool
    {
        $hashes = $admin->mfa_recovery_codes ?? [];
        $hash = $this->hash(Str::lower($code));

        if (! in_array($hash, $hashes, true)) {
            return false;
        }

        $remaining = array_values(array_diff($hashes, [$hash]));
        $admin->forceFill(['mfa_recovery_codes' => $remaining])->save();

        $this->alert($admin, 'Recovery code used', "A recovery code was used to sign in as {$admin->email}. ".count($remaining).' code(s) left.');

        return true;
    }

    /** Five characters with no look-alikes (no 0/o, 1/l/i), so a code copied by hand is typed back right. */
    private function randomChunk(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';

        return implode('', array_map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)], range(1, 5)));
    }

    // Recovery codes are ~50 random bits each, so a fast hash is enough.
    private function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
