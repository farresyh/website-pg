<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * ADR-066 cutover addendum — the permanent fix for the first-boot
 * super-admin gotcha. `ProductionSeeder` used to read
 * `env('ADMIN_EMAIL')` / `env('ADMIN_PASSWORD')` at runtime; once the
 * deploy script has run `php artisan config:cache`, Laravel stops
 * loading `.env` for artisan too, so both came back null and the
 * seeder silently skipped super-admin creation (admin login then
 * failed with a generic "credential incorrect").
 *
 * This command is the single seam for creating that account. Values
 * come from `--email` / `--password` / `--name` when given, otherwise
 * from `config('admin.seed_*')` (env is read there, inside a config
 * file, so `config:cache` bakes it in while `.env` is still loaded).
 * ProductionSeeder just calls this command.
 *
 * Idempotent and deploy-safe:
 *  - blank email/password  -> warn + exit 0 (a deploy without those
 *    vars set must not fail; the founder can run this by hand later)
 *  - email already exists, no --force -> info + exit 0
 *  - email already exists, --force -> reset password, reactivate,
 *    ensure the super_admin role
 *  - otherwise -> create a new super_admin
 * Only a real validation error (bad email, password under 8 chars)
 * exits non-zero.
 */
#[Signature('app:create-admin
    {--email= : Email address; falls back to config(admin.seed_email)}
    {--password= : Plain password (min 8); falls back to config(admin.seed_password)}
    {--name= : Display name; falls back to config(admin.seed_name)}
    {--force : Reset the password / reactivate / re-grant super_admin if the account already exists}')]
#[Description('Create (or --force update) the first super-admin account, from options or config/admin.php.')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        $email = $this->stringOption('email') ?? $this->configString('admin.seed_email');
        $password = $this->stringOption('password') ?? $this->configString('admin.seed_password');
        $name = $this->stringOption('name') ?? $this->configString('admin.seed_name') ?? 'Admin';

        if ($email === null || $password === null) {
            $this->warn('app:create-admin: no email/password given and config(admin.seed_email|seed_password) is empty — skipping.');

            return self::SUCCESS;
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'name' => $name],
            [
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
                'name' => ['required', 'string', 'max:255'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error("app:create-admin: {$error}");
            }

            return self::FAILURE;
        }

        $existing = AdminUser::query()->where('email', $email)->first();

        if ($existing !== null && ! $this->option('force')) {
            $this->info("app:create-admin: {$email} already exists — skipping (pass --force to reset it).");

            return self::SUCCESS;
        }

        if ($existing !== null) {
            $existing->update([
                'password' => $password, // AdminUser 'password' cast => 'hashed'
                'role' => 'super_admin',
                'is_active' => true,
            ]);

            $this->info("app:create-admin: reset password + re-granted super_admin for {$email}.");

            return self::SUCCESS;
        }

        AdminUser::create([
            'name' => $name,
            'email' => $email,
            'password' => $password, // AdminUser 'password' cast => 'hashed'
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->info("app:create-admin: created super admin {$email}.");

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
