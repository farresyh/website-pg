<?php

namespace App\Services\Reseller;

use App\Models\AffiliateUser;
use App\Models\LedgerAccount;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Models\ResellerStatusChange;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-142 decisions 15–18: the one writer of `resellers.is_active`, and the
 * one answer to "may this reseller act, and if not, what do we tell it".
 *
 * Every write holds the reseller's wallet `ledger_accounts` row, the same
 * mutex every debit and top-up credit takes (ADR-131 decision 12), so an
 * order and a deactivation never cross: the order placement re-reads the
 * flag under that lock (`lockActive()`).
 */
final class ResellerAccountStatus
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Switch the reseller off for `$reason`. A reseller already off for the
     * same reason is left as it is; one already off for another reason takes
     * the new one (a manual or dormant account that is then closed).
     * `$onlyIf` runs under the lock and can still call it off (the dormancy
     * job re-checks the idle spell there). Returns whether anything changed.
     *
     * @param  (Closure(Reseller): bool)|null  $onlyIf
     */
    public function deactivate(Reseller $reseller, ResellerDeactivationReason $reason, ?int $adminUserId, ?Closure $onlyIf = null): bool
    {
        $changed = DB::transaction(function () use ($reseller, $reason, $adminUserId, $onlyIf) {
            $locked = $this->lock($reseller);

            if ((! $locked->is_active && $locked->deactivation_reason === $reason) || ($onlyIf !== null && ! $onlyIf($locked))) {
                return false;
            }

            $locked->forceFill([
                'is_active' => false,
                'deactivation_reason' => $reason,
                'deactivated_at' => now(),
            ])->save();

            ResellerStatusChange::query()->create([
                'reseller_id' => $locked->id,
                'is_active' => false,
                'reason' => $reason,
                'admin_user_id' => $adminUserId,
            ]);

            // Open portal sessions end now; the next login is refused with the reason.
            $locked->users()->get()->each(fn (AffiliateUser $user) => $user->tokens()->delete());

            return true;
        });

        if ($changed) {
            $reseller->forceFill(['is_active' => false, 'deactivation_reason' => $reason]);
            Log::info('Reseller deactivated', ['reseller_id' => $reseller->id, 'reason' => $reason->value, 'admin_user_id' => $adminUserId]);
        }

        return $changed;
    }

    /** Switch it back on. A closed (soft-deleted) reseller cannot come back. Returns whether anything changed. */
    public function reactivate(Reseller $reseller, int $adminUserId): bool
    {
        $changed = DB::transaction(function () use ($reseller, $adminUserId) {
            $locked = $this->lock($reseller);

            if ($locked->is_active || $locked->trashed()) {
                return false;
            }

            $locked->forceFill(['is_active' => true, 'deactivation_reason' => null, 'deactivated_at' => null])->save();

            ResellerStatusChange::query()->create([
                'reseller_id' => $locked->id,
                'is_active' => true,
                'reason' => null,
                'admin_user_id' => $adminUserId,
            ]);

            return true;
        });

        if ($changed) {
            $reseller->forceFill(['is_active' => true, 'deactivation_reason' => null]);
            Log::info('Reseller reactivated', ['reseller_id' => $reseller->id, 'admin_user_id' => $adminUserId]);
        }

        return $changed;
    }

    /**
     * For a caller already inside a transaction that will debit or credit this
     * wallet: take the wallet lock first, then re-read the flag, so an order
     * cannot slip in after a deactivation committed (ADR-142 decision 18).
     *
     * @throws ResellerInactiveException
     */
    public function lockActive(Reseller $reseller): void
    {
        $this->assertActive($this->lock($reseller));
    }

    /** @throws ResellerInactiveException */
    public function assertActive(Reseller $reseller): void
    {
        if (($denial = $this->denial($reseller)) !== null) {
            throw new ResellerInactiveException($denial);
        }
    }

    public function denial(Reseller $reseller): ?ResellerDenial
    {
        if ($reseller->is_active && ! $reseller->trashed()) {
            return null;
        }

        $reason = $reseller->trashed() ? ResellerDeactivationReason::Closed : ($reseller->deactivation_reason ?? ResellerDeactivationReason::Manual);
        $settings = PlatformSettings::current();
        $number = $settings->reseller_support_whatsapp;

        return match ($reason) {
            ResellerDeactivationReason::Dormant => new ResellerDenial(
                $reason,
                "This account was deactivated after {$settings->reseller_dormancy_days} days with no transaction. "
                    .($number !== null ? "WhatsApp us at {$number} to reactivate it." : 'Contact PekanGame to reactivate it.'),
                "Akaun reseller ini dinyahaktifkan kerana tiada transaksi selama {$settings->reseller_dormancy_days} hari. "
                    .($number !== null ? "WhatsApp kami di {$number} untuk aktifkan semula." : 'Sila hubungi admin untuk aktifkan semula.'),
            ),
            ResellerDeactivationReason::Closed => new ResellerDenial(
                $reason,
                'This account is closed.',
                'Akaun reseller ini telah ditutup.',
            ),
            ResellerDeactivationReason::Manual => new ResellerDenial(
                $reason,
                'This account has been deactivated. '.($number !== null ? "WhatsApp us at {$number}." : 'Contact PekanGame.'),
                'Akaun reseller ini telah dinyahaktifkan. '.($number !== null ? "WhatsApp kami di {$number}." : 'Sila hubungi admin.'),
            ),
        };
    }

    /**
     * The wallet mutex, then a fresh read of the reseller under it.
     *
     * Only locking reads before the lock is held. MySQL's REPEATABLE READ
     * fixes a transaction's snapshot at its first plain SELECT; a plain read
     * here (an earlier `openAccount()` firstOrCreate) froze the snapshot
     * before the lock, so the order's later balance check missed a rival
     * debit and two orders overspent the wallet
     * (ResellerOrderPlacementConcurrencyTest caught it, build-log 2026-11-16).
     */
    private function lock(Reseller $reseller): Reseller
    {
        $account = fn () => LedgerAccount::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->where('owner_id', $reseller->id)
            ->lockForUpdate()
            ->first();

        if ($account() === null) {
            // Only an account made without a wallet (old test fixtures); every real one has it from approval.
            $this->ledger->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
            $account() ?? throw new \RuntimeException("Reseller #{$reseller->id} has no wallet account.");
        }

        return Reseller::withTrashed()->lockForUpdate()->findOrFail($reseller->id);
    }
}
