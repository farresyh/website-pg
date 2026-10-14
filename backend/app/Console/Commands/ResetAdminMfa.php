<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Services\Auth\AdminMfa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * ADR-121 decision 7: the only way to turn an admin's MFA off — run over
 * SSH for a lost phone plus lost recovery codes. Clears the secret, the
 * recovery codes and the require-MFA flag, ends every session of that
 * admin, and emails a security alert. A super admin can then require MFA
 * again, and the admin re-enrols on the next login.
 */
#[Signature('admin:mfa-reset {email : The admin account to reset}')]
#[Description('Turn off MFA for one admin and end all of their sessions (ADR-121)')]
class ResetAdminMfa extends Command
{
    public function handle(AdminMfa $mfa): int
    {
        $admin = AdminUser::query()->where('email', $this->argument('email'))->first();

        if (! $admin) {
            $this->error('No admin with that email.');

            return self::FAILURE;
        }

        $mfa->reset($admin);
        $this->info("MFA reset for {$admin->email}; all of their sessions were ended.");

        return self::SUCCESS;
    }
}
