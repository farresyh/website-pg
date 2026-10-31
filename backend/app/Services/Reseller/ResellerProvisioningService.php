<?php

namespace App\Services\Reseller;

use App\Models\AffiliateUser;
use App\Models\Reseller;
use App\Services\Affiliate\AffiliateInviteService;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;

/**
 * ADR-133 decision 21: the one place a Reseller is created and given a portal login. The admin
 * screens call it today; the application approval (a later PR) calls it too, so there is never a
 * second copy of the steps below.
 */
final class ResellerProvisioningService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AffiliateInviteService $invites,
    ) {}

    /**
     * The reseller row plus its wallet ledger account. ADR-073 decision 3: the account is opened
     * at creation, not at the first top-up, because `LedgerService::debit()` locks an existing
     * row and would otherwise fail on an account that was never opened.
     *
     * @param  array{business_name: string, contact_name?: ?string, email?: ?string, phone?: ?string, reseller_tier_id?: ?int, notes?: ?string}  $details
     */
    public function create(array $details): Reseller
    {
        return DB::transaction(function () use ($details) {
            $reseller = Reseller::query()->create([
                'business_name' => $details['business_name'],
                'contact_name' => $details['contact_name'] ?? null,
                'email' => $details['email'] ?? null,
                'phone' => $details['phone'] ?? null,
                'reseller_tier_id' => $details['reseller_tier_id'] ?? null,
                'is_active' => true,
                'notes' => $details['notes'] ?? null,
            ]);

            $this->ledger->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

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
}
