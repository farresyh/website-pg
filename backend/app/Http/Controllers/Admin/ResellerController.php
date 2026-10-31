<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignResellerTierRequest;
use App\Http\Requests\Admin\CloseResellerRequest;
use App\Http\Requests\Admin\StoreInternalResellerRequest;
use App\Http\Requests\Admin\StoreResellerRequest;
use App\Http\Requests\Admin\StoreResellerUserRequest;
use App\Http\Requests\Admin\UpdateResellerBalanceCapRequest;
use App\Http\Requests\Admin\UpdateResellerRequest;
use App\Http\Requests\Admin\UpdateResellerStatusRequest;
use App\Models\AffiliateUser;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Services\Affiliate\AffiliateInviteService;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Partner\PartnerReadinessService;
use App\Services\Reseller\ResellerClosureService;
use App\Services\Reseller\ResellerProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ADR-072/073 PR-B: super_admin-only Reseller (prepaid-wallet) account
 * management — register account, assign tier, activate/deactivate.
 * Wallet balance is always derived from the ledger (ADR-002) — there is
 * no balance column.
 *
 * PR-G: `storeUser()`/`resendInvite()` add this account's portal login
 * (ADR-072 decision 5) — reuses `AffiliateInviteService` (generalized
 * for `owner_type`), same shape `Admin\AffiliateController`'s own
 * staff-login actions already use. Registration itself stays
 * admin-created, no self-serve signup (PR-G planning addendum decision 1).
 */
class ResellerController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AffiliateInviteService $invites,
        private readonly ResellerProvisioningService $provisioning,
        private readonly ResellerClosureService $closures,
        private readonly PartnerReadinessService $readiness,
    ) {}

    /** ADR-131 decision 4: the platform default cap, read once per request, not once per row. */
    private ?int $defaultBalanceCapSen = null;

    public function index(): JsonResponse
    {
        $resellers = Reseller::query()
            ->with(['tier' => fn ($q) => $q->withTrashed()])
            ->orderBy('business_name')
            ->get();

        $balances = $this->ledger->balances(LedgerOwnerType::ResellerWallet, $resellers->pluck('id')->all());

        $readiness = $this->readiness->forResellers($resellers);

        return response()->json([
            'resellers' => $resellers->map(fn (Reseller $r) => $this->rowShape($r, $balances[$r->id] ?? 0)
                + ['readiness' => $readiness[$r->id]->toSummary()])->all(),
        ]);
    }

    public function show(Reseller $reseller): JsonResponse
    {
        $reseller->load(['tier' => fn ($q) => $q->withTrashed(), 'users']);

        return response()->json($this->rowShape($reseller, $this->ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id)));
    }

    /** ADR-133 decision 21: the reseller, its wallet account and its portal login, in `ResellerProvisioningService`. */
    public function store(StoreResellerRequest $request): JsonResponse
    {
        $reseller = $this->provisioning->provision($request->validated());

        Log::info('Reseller created', [
            'reseller_id' => $reseller->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json($this->rowShape($reseller->fresh(['tier', 'users']), 0), 201);
    }

    /** ADR-133 decision 12(c): a test or internal account, no KYC on file. Behind step-up. */
    public function storeInternal(StoreInternalResellerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $reseller = $this->provisioning->provision(['internal_reason' => $data['reason']] + $data);

        Log::info('Internal reseller account created', [
            'reseller_id' => $reseller->id,
            'admin_user_id' => $request->user()->id,
            'reason' => $data['reason'],
        ]);

        return response()->json($this->rowShape($reseller->fresh(['tier', 'users']), 0), 201);
    }

    public function update(UpdateResellerRequest $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validated();

        $reseller->update([
            'business_name' => $data['business_name'],
            'contact_name' => $data['contact_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json($this->rowShape($reseller->fresh(), $this->ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id)));
    }

    /** ADR-073 decision 1: direct FK swap, effective immediately, no billing cycle. */
    public function assignTier(AssignResellerTierRequest $request, Reseller $reseller): JsonResponse
    {
        $reseller->update(['reseller_tier_id' => $request->validated('reseller_tier_id')]);

        return response()->json($this->rowShape($reseller->fresh(), $this->ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id)));
    }

    /**
     * ADR-072 decision 9: activate / deactivate. Does not freeze or zero
     * the wallet balance — a deactivated reseller simply can't place new
     * orders on either channel once PR-D/E/F ship.
     */
    public function updateStatus(UpdateResellerStatusRequest $request, Reseller $reseller): JsonResponse
    {
        $isActive = $request->validated('is_active');
        $reseller->update(['is_active' => $isActive]);

        Log::info('Reseller status changed', [
            'reseller_id' => $reseller->id,
            'is_active' => $isActive,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json($this->rowShape($reseller->fresh(), $this->ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id)));
    }

    /** ADR-131 decision 12: what would stop a closure, and what it would refund, without changing anything. */
    public function closeCheck(Reseller $reseller): JsonResponse
    {
        $blockers = $this->closures->blockers($reseller);

        return response()->json([
            'balance_sen' => $this->ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id),
            'blockers' => $blockers,
            'can_close' => $blockers === [],
        ]);
    }

    /**
     * ADR-131 decisions 12-16: the only way to remove a Reseller. The refund was sent by bank
     * transfer first; this records it and closes the account. Behind step-up.
     */
    public function close(CloseResellerRequest $request, Reseller $reseller): JsonResponse
    {
        $refunded = $this->closures->close(
            $reseller,
            $request->user()->id,
            $request->validated('transferred_on'),
            $request->validated('bank_reference'),
        );

        return response()->json(['message' => 'Account closed.', 'refunded_sen' => $refunded]);
    }

    /**
     * PR-G: add a portal login for this Reseller account. Mirrors
     * `AffiliateController::storeUser()` exactly — null password, the
     * new user gets the set-password invite.
     */
    public function storeUser(StoreResellerUserRequest $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validated();

        $this->provisioning->addPortalUser($reseller, $data['name'], $data['email']);

        return response()->json($this->rowShape(
            $reseller->fresh(['tier' => fn ($q) => $q->withTrashed(), 'users']),
            $this->ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id),
        ));
    }

    /** Re-send the set-password invite for a portal user who hasn't accepted yet. */
    public function resendInvite(Reseller $reseller, AffiliateUser $affiliateUser): JsonResponse
    {
        abort_unless(
            $affiliateUser->owner_type === AccountOwnerType::Reseller && $affiliateUser->owner_id === $reseller->id,
            404,
        );

        if ($affiliateUser->password !== null) {
            throw ValidationException::withMessages([
                'user' => ['This user has already set their password.'],
            ]);
        }

        $this->invites->sendInvite($affiliateUser);

        return response()->json(['message' => 'Invite re-sent.']);
    }

    /** ADR-131 decision 4: set (or clear, with null) one reseller's balance-cap override. Behind step-up. */
    public function updateBalanceCap(UpdateResellerBalanceCapRequest $request, Reseller $reseller): JsonResponse
    {
        $reseller->update(['balance_cap_sen' => $request->validated('balance_cap_sen')]);

        Log::info('Reseller balance cap changed', [
            'reseller_id' => $reseller->id,
            'balance_cap_sen' => $reseller->balance_cap_sen,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json($this->rowShape($reseller->fresh(), $this->ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id)));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowShape(Reseller $reseller, int $walletBalanceSen): array
    {
        return [
            'id' => $reseller->id,
            'business_name' => $reseller->business_name,
            'contact_name' => $reseller->contact_name,
            'email' => $reseller->email,
            'phone' => $reseller->phone,
            'reseller_tier_id' => $reseller->reseller_tier_id,
            'tier_name' => $reseller->tier?->name,
            'markup_percent' => $reseller->tier?->markup_percent,
            'is_active' => (bool) $reseller->is_active,
            'notes' => $reseller->notes,
            // ADR-133 decision 12: an internal account has no KYC on file; every other account
            // so far had its documents collected outside the system (PR 3 adds "on file").
            'kyc' => $reseller->internal_reason !== null ? 'none' : 'outside_system',
            'internal_reason' => $reseller->internal_reason,
            'deleted_at' => $reseller->deleted_at,
            'wallet_balance_sen' => $walletBalanceSen,
            'balance_cap_sen' => $reseller->balance_cap_sen,
            'effective_balance_cap_sen' => $reseller->balance_cap_sen
                ?? ($this->defaultBalanceCapSen ??= (int) PlatformSettings::current()->balance_cap_default_sen),
            'users' => $reseller->relationLoaded('users')
                ? $reseller->users->map(fn (AffiliateUser $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'is_active' => $u->is_active,
                    'last_login_at' => $u->last_login_at,
                    'invite_pending' => $u->password === null,
                ])->all()
                : [],
        ];
    }
}
