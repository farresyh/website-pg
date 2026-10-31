<?php

namespace App\Services\Reseller;

use App\Models\AffiliateUser;
use App\Models\Reseller;
use App\Services\Affiliate\AffiliateInviteService;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ADR-133 decision 21: the one place a Reseller is created and given a portal login. Both admin
 * create paths call it today; the application approval (PR 3) calls it too, so there is never a
 * second copy of the steps below.
 */
final class ResellerProvisioningService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AffiliateInviteService $invites,
    ) {}

    /**
     * The reseller, its wallet ledger account and its first portal login (the contact name and
     * email), with the set-password invite: all or nothing, so a failed invite leaves no account
     * behind. The tier is required: without one the account can neither order nor add balance.
     *
     * @param  array{business_name: string, contact_name: string, email: string, phone?: ?string, reseller_tier_id: ?int, notes?: ?string, internal_reason?: ?string}  $details
     */
    public function provision(array $details): Reseller
    {
        if (($details['reseller_tier_id'] ?? null) === null) {
            throw new InvalidArgumentException('A reseller is provisioned with a tier.');
        }

        return DB::transaction(function () use ($details) {
            $reseller = $this->create($details);
            $this->addPortalUser($reseller, $details['contact_name'], $details['email']);

            return $reseller;
        });
    }

    /**
     * A portal login with no password yet, and its set-password invite. The invite is sent inside
     * the transaction on purpose: if the email provider fails, no half-made user is left behind.
     */
    public function addPortalUser(Reseller $reseller, string $name, string $email): AffiliateUser
    {
        return DB::transaction(function () use ($reseller, $name, $email) {
            $user = AffiliateUser::query()->create([
                'owner_type' => AccountOwnerType::Reseller->value,
                'owner_id' => $reseller->id,
                'name' => $name,
                'email' => $email,
                'password' => null,
                'is_active' => true,
            ]);

            $this->invites->sendInvite($user);

            return $user;
        });
    }

    /**
     * ADR-073 decision 3: the wallet ledger account is opened at creation, not at the first
     * top-up, because `LedgerService::debit()` locks an existing row and would otherwise fail on
     * an account that was never opened. Runs inside provision()'s transaction.
     *
     * @param  array<string, mixed>  $details
     */
    private function create(array $details): Reseller
    {
        $reseller = Reseller::query()->create([
            'business_name' => $details['business_name'],
            'contact_name' => $details['contact_name'],
            'email' => $details['email'],
            'phone' => $details['phone'] ?? null,
            'reseller_tier_id' => $details['reseller_tier_id'],
            'is_active' => true,
            'notes' => $details['notes'] ?? null,
            'internal_reason' => $details['internal_reason'] ?? null,
        ]);

        $this->ledger->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }
}
