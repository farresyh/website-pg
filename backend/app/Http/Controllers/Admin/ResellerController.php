<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignResellerTierRequest;
use App\Http\Requests\Admin\StoreResellerRequest;
use App\Http\Requests\Admin\StoreResellerUserRequest;
use App\Http\Requests\Admin\UpdateResellerRequest;
use App\Http\Requests\Admin\UpdateResellerStatusRequest;
use App\Models\AffiliateUser;
use App\Models\Reseller;
use App\Services\Affiliate\AffiliateInviteService;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
    ) {}

    public function index(): JsonResponse
    {
        $resellers = Reseller::query()
            ->with(['tier' => fn ($q) => $q->withTrashed()])
            ->orderBy('business_name')
            ->get();

        $balances = $this->ledger->balances(LedgerOwnerType::ResellerWallet, $resellers->pluck('id')->all());

        return response()->json([
            'resellers' => $resellers->map(fn (Reseller $r) => $this->rowShape($r, $balances[$r->id] ?? 0))->all(),
        ]);
    }

    public function show(Reseller $reseller): JsonResponse
    {
        $reseller->load(['tier' => fn ($q) => $q->withTrashed(), 'users']);

        return response()->json($this->rowShape($reseller, $this->ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id)));
    }

    /**
     * ADR-073 decision 3: the wallet ledger account is opened proactively
     * at creation, not deferred to the first top-up — `LedgerService::
     * debit()`'s `lockForUpdate()->firstOrFail()` would otherwise throw a
     * hard "row not found" against a `ledger_accounts` row that was never
     * opened, the same reasoning `AffiliateController::store()` already
     * follows for a new `Affiliate`.
     */
    public function store(StoreResellerRequest $request): JsonResponse
    {
        $data = $request->validated();

        $reseller = DB::transaction(function () use ($data) {
            $reseller = Reseller::query()->create([
                'business_name' => $data['business_name'],
                'contact_name' => $data['contact_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'reseller_tier_id' => $data['reseller_tier_id'] ?? null,
                'is_active' => true,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->ledger->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

            return $reseller;
        });

        Log::info('Reseller created', [
            'reseller_id' => $reseller->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json($this->rowShape($reseller->fresh(), 0), 201);
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

    /**
     * Soft-delete only, and only when the wallet balance is exactly
     * zero — a reseller holding real deposited money can't be deleted
     * out from under that balance. Mirrors `AffiliateController::
     * destroy()`'s own zero-balance guard.
     */
    public function destroy(Reseller $reseller): JsonResponse
    {
        $balance = $this->ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id);
        if ($balance !== 0) {
            throw ValidationException::withMessages([
                'reseller' => ["This reseller has a non-zero wallet balance ({$balance} sen). Refund it before deleting."],
            ]);
        }

        $reseller->update(['is_active' => false]);
        $reseller->delete();

        Log::info('Reseller soft-deleted', ['reseller_id' => $reseller->id]);

        return response()->json(['message' => 'Reseller deleted.']);
    }

    /**
     * PR-G: add a portal login for this Reseller account. Mirrors
     * `AffiliateController::storeUser()` exactly — null password, the
     * new user gets the set-password invite.
     */
    public function storeUser(StoreResellerUserRequest $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $reseller) {
            $user = AffiliateUser::query()->create([
                'owner_type' => AccountOwnerType::Reseller->value,
                'owner_id' => $reseller->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => null,
                'is_active' => true,
            ]);

            $this->invites->sendInvite($user);
        });

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
            'deleted_at' => $reseller->deleted_at,
            'wallet_balance_sen' => $walletBalanceSen,
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
