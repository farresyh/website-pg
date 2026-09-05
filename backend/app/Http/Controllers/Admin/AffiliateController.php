<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignAffiliateTierRequest;
use App\Http\Requests\Admin\StoreAffiliateRequest;
use App\Http\Requests\Admin\StoreAffiliateUserRequest;
use App\Http\Requests\Admin\UpdateAffiliateRequest;
use App\Http\Requests\Admin\UpdateAffiliateStatusRequest;
use App\Models\Affiliate;
use App\Models\AffiliateImpersonationSession;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateUser;
use App\Services\Affiliate\AffiliateInviteService;
use App\Services\Affiliate\AffiliateSubscriptionService;
use App\Services\Affiliate\AffiliateTierFeeService;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Withdrawal\WithdrawalStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ADR-058 58b (RES-1..3, RES-5, RES-6): super_admin-only Affiliate
 * Management. Same role tier as Settings / Blacklist / Membership —
 * route-level `admin.role:super_admin` is the gate, this controller
 * assumes it already ran.
 *
 * Impersonation (RES-4) lives in AffiliateImpersonationController; the
 * `affiliate_membership_tiers` CRUD lives in
 * AffiliateMembershipTierController. Affiliate balances are always derived
 * from the ledger (ADR-002) — there is no balance column.
 */
class AffiliateController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AffiliateSubscriptionService $subscriptions,
        private readonly AffiliateInviteService $invites,
        private readonly AffiliateTierFeeService $tierFees,
    ) {}

    /**
     * RES-1: the affiliate table. Earnings balance is batched into one
     * grouped ledger query, not one per row.
     */
    public function index(): JsonResponse
    {
        $affiliates = Affiliate::query()
            ->with(['subscription.tier' => fn ($q) => $q->withTrashed()])
            ->withCount('orders')
            ->orderBy('business_name')
            ->get();

        $balances = $this->ledger->balances(LedgerOwnerType::Affiliate, $affiliates->pluck('id')->all());

        return response()->json([
            'affiliates' => $affiliates->map(fn (Affiliate $r) => $this->rowShape($r, $balances[$r->id] ?? 0))->all(),
        ]);
    }

    public function show(Affiliate $affiliate): JsonResponse
    {
        return response()->json($this->detailShape($affiliate));
    }

    /**
     * RES-2: create the Affiliate row + its first affiliate_user (null
     * password → set-password invite), optionally assign an initial
     * wholesale tier. The user create + invite send are wrapped in the
     * same transaction as the affiliate create; a Plunk failure rolls the
     * whole thing back so the admin retries cleanly rather than being
     * left with a half-created affiliate.
     */
    public function store(StoreAffiliateRequest $request): JsonResponse
    {
        $data = $request->validated();

        // ADR-061 decision 8: consumer Membership is an internal-brand-only
        // capability — a third-party affiliate can never turn it on, so the
        // toggle is ignored unless "our own brand" is set.
        $isOwned = (bool) ($data['is_owned'] ?? false);
        $membershipEnabled = $isOwned && (bool) ($data['membership_enabled'] ?? false);

        $affiliate = DB::transaction(function () use ($data, $request, $isOwned, $membershipEnabled) {
            $affiliate = Affiliate::query()->create([
                'business_name' => $data['business_name'],
                'contact_name' => $data['contact_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'markup_pct' => $data['markup_pct'],
                'max_markup_pct' => $data['max_markup_pct'] ?? null,
                'domains' => $data['domains'] ?? null,
                'status' => 'active',
                'is_owned' => $isOwned,
                'membership_enabled' => $membershipEnabled,
                'notes' => $data['notes'] ?? null,
            ]);

            $user = AffiliateUser::query()->create([
                'owner_type' => AccountOwnerType::Affiliate->value,
                'owner_id' => $affiliate->id,
                'name' => $data['user_name'],
                'email' => $data['user_email'],
                'password' => null,
                'is_active' => true,
            ]);

            if (! empty($data['tier_id'])) {
                $tier = AffiliateMembershipTier::query()->findOrFail($data['tier_id']);
                $this->subscriptions->assignTier($affiliate, $tier, $request->user()->id, 'Initial tier (RES-2)');
            }

            $this->invites->sendInvite($user);

            return $affiliate;
        });

        Log::info('Affiliate created', [
            'affiliate_id' => $affiliate->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json($this->detailShape($affiliate->fresh()), 201);
    }

    /** RES-3: edit business details + markup ceiling + brand/membership flags. */
    public function update(UpdateAffiliateRequest $request, Affiliate $affiliate): JsonResponse
    {
        $data = $request->validated();

        // ADR-061 decision 8: only an internal brand may carry consumer
        // Membership. Resolve `is_owned` first (may be unchanged), then
        // force the toggle off for a third-party affiliate. The primary
        // affiliate is `is_owned` by definition (decision 3) — it cannot
        // be un-owned while it is the fallback tenant.
        $isOwned = $affiliate->is_primary
            || (array_key_exists('is_owned', $data) ? (bool) $data['is_owned'] : (bool) $affiliate->is_owned);
        $membershipEnabled = $isOwned
            && (array_key_exists('membership_enabled', $data)
                ? (bool) $data['membership_enabled']
                : (bool) $affiliate->membership_enabled);

        $affiliate->update([
            'business_name' => $data['business_name'],
            'contact_name' => $data['contact_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'markup_pct' => $data['markup_pct'],
            'max_markup_pct' => $data['max_markup_pct'] ?? null,
            'domains' => $data['domains'] ?? null,
            'is_owned' => $isOwned,
            'membership_enabled' => $membershipEnabled,
            'notes' => $data['notes'] ?? null,
        ]);

        // The public catalog caches the primary brand's markup into every
        // game's `price_from_sen` / package price (CatalogController::
        // sellingPriceSen) and gates member pricing on the effective
        // Membership flag — both must be re-derived when either changes.
        if ($affiliate->wasChanged(['markup_pct', 'membership_enabled'])) {
            CatalogController::forgetPackagesCacheForMembership();
            CatalogController::forgetIndexCache();
        }

        return response()->json($this->detailShape($affiliate->fresh()));
    }

    /** RES-3 / ADR-056 decision 8: assign or change the wholesale tier. */
    public function assignTier(AssignAffiliateTierRequest $request, Affiliate $affiliate): JsonResponse
    {
        $tier = AffiliateMembershipTier::query()->findOrFail($request->validated('tier_id'));

        $this->subscriptions->assignTier(
            $affiliate,
            $tier,
            $request->user()->id,
            $request->validated('note'),
        );

        return response()->json($this->detailShape($affiliate->fresh()));
    }

    /**
     * ADR-056 decision 6: trigger this affiliate's tier-fee charge now
     * instead of waiting for the scheduled run — debits earnings, or
     * starts/continues grace if earnings are short.
     */
    public function chargeTierFee(Affiliate $affiliate): JsonResponse
    {
        $subscription = $affiliate->subscription;

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'tier' => ['This affiliate has no wholesale-tier subscription to charge.'],
            ]);
        }

        $this->tierFees->chargeCycle($subscription);

        return response()->json($this->detailShape($affiliate->fresh()));
    }

    /**
     * ADR-056 decision 3/6: reactivation is an explicit admin action.
     * Brings a grace/lapsed subscription back to active with a fresh
     * 30-day cycle (does not itself charge the fee).
     */
    public function reactivateSubscription(Affiliate $affiliate): JsonResponse
    {
        $subscription = $affiliate->subscription;

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'tier' => ['This affiliate has no wholesale-tier subscription to reactivate.'],
            ]);
        }

        $this->subscriptions->reactivate($subscription);

        return response()->json($this->detailShape($affiliate->fresh()));
    }

    /** Add another staff login to an existing affiliate (+ invite). */
    public function storeUser(StoreAffiliateUserRequest $request, Affiliate $affiliate): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $affiliate) {
            $user = AffiliateUser::query()->create([
                'owner_type' => AccountOwnerType::Affiliate->value,
                'owner_id' => $affiliate->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => null,
                'is_active' => true,
            ]);

            $this->invites->sendInvite($user);
        });

        return response()->json($this->detailShape($affiliate->fresh()));
    }

    /** Re-send the set-password invite for a user who hasn't accepted yet. */
    public function resendInvite(Affiliate $affiliate, AffiliateUser $affiliateUser): JsonResponse
    {
        abort_unless(
            $affiliateUser->owner_type === AccountOwnerType::Affiliate && $affiliateUser->owner_id === $affiliate->id,
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

    /** RES-5: activate / deactivate. Deactivating ends any live impersonation. */
    public function updateStatus(UpdateAffiliateStatusRequest $request, Affiliate $affiliate): JsonResponse
    {
        $status = $request->validated('status');
        $affiliate->update(['status' => $status]);

        if ($status === 'inactive') {
            $this->endImpersonationSessions($affiliate, 'affiliate_deactivated');
        }

        Log::info('Affiliate status changed', [
            'affiliate_id' => $affiliate->id,
            'status' => $status,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json($this->detailShape($affiliate->fresh()));
    }

    /**
     * RES-6: soft-delete only, and only when there is nothing owed — the
     * earnings balance is exactly zero and no withdrawal is pending or
     * approved-but-uncompleted. The Cloudflare custom-hostname teardown
     * is ADR-060, not wired here.
     *
     * ADR-061 decision 8: the primary affiliate is the console/job/migration
     * fallback tenant and can never be deleted. A non-primary `is_owned`
     * brand (a future internal brand, or one being sold off) follows the
     * normal rules below.
     */
    public function destroy(Affiliate $affiliate): JsonResponse
    {
        if ($affiliate->is_primary) {
            throw ValidationException::withMessages([
                'affiliate' => ['The primary affiliate cannot be deleted.'],
            ]);
        }

        $balance = $this->ledger->balance(LedgerOwnerType::Affiliate, $affiliate->id);
        if ($balance !== 0) {
            throw ValidationException::withMessages([
                'affiliate' => ["This affiliate has a non-zero earnings balance ({$balance} sen). Settle it before deleting."],
            ]);
        }

        $hasOpenWithdrawal = DB::table('withdrawals')
            ->where('owner_type', LedgerOwnerType::Affiliate->value)
            ->where('owner_id', $affiliate->id)
            ->whereIn('status', [WithdrawalStatus::Pending->value, WithdrawalStatus::Approved->value])
            ->exists();

        if ($hasOpenWithdrawal) {
            throw ValidationException::withMessages([
                'affiliate' => ['This affiliate has a pending or approved withdrawal. Complete or reject it before deleting.'],
            ]);
        }

        $this->endImpersonationSessions($affiliate, 'affiliate_deleted');

        $affiliate->update(['status' => 'inactive']);
        $affiliate->delete();

        Log::info('Affiliate soft-deleted', ['affiliate_id' => $affiliate->id]);

        return response()->json(['message' => 'Affiliate deleted.']);
    }

    private function endImpersonationSessions(Affiliate $affiliate, string $reason): void
    {
        $sessions = AffiliateImpersonationSession::query()
            ->where('affiliate_id', $affiliate->id)
            ->whereNull('ended_at')
            ->get();

        foreach ($sessions as $session) {
            if ($session->personal_access_token_id !== null) {
                DB::table('personal_access_tokens')->where('id', $session->personal_access_token_id)->delete();
            }
            $session->update(['ended_at' => now(), 'ended_reason' => $reason]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function detailShape(Affiliate $affiliate): array
    {
        $affiliate->load([
            'users',
            'tierChanges.admin:id,name',
            'subscription.tier' => fn ($q) => $q->withTrashed(),
            'tierChanges.fromTier' => fn ($q) => $q->withTrashed(),
            'tierChanges.toTier' => fn ($q) => $q->withTrashed(),
        ]);
        $affiliate->loadCount('orders');

        return [
            'affiliate' => $this->rowShape($affiliate, $this->ledger->balance(LedgerOwnerType::Affiliate, $affiliate->id)),
            'users' => $affiliate->users->map(fn (AffiliateUser $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => $u->is_active,
                'last_login_at' => $u->last_login_at,
                'invite_pending' => $u->password === null,
            ])->all(),
            'tier_changes' => $affiliate->tierChanges->sortByDesc('created_at')->values()->map(fn ($c) => [
                'id' => $c->id,
                'from_tier' => $c->fromTier?->name,
                'to_tier' => $c->toTier?->name,
                'admin' => $c->admin?->name,
                'note' => $c->note,
                'created_at' => $c->created_at,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowShape(Affiliate $affiliate, int $earningsBalanceSen): array
    {
        $subscription = $affiliate->subscription;

        return [
            'id' => $affiliate->id,
            'business_name' => $affiliate->business_name,
            'contact_name' => $affiliate->contact_name,
            'email' => $affiliate->email,
            'phone' => $affiliate->phone,
            'markup_pct' => $affiliate->markup_pct,
            'max_markup_pct' => $affiliate->max_markup_pct,
            'domains' => $affiliate->domains ?? [],
            'status' => $affiliate->status,
            'notes' => $affiliate->notes,
            'is_owned' => (bool) $affiliate->is_owned,
            'is_primary' => (bool) $affiliate->is_primary,
            'membership_enabled' => (bool) $affiliate->membership_enabled,
            'deleted_at' => $affiliate->deleted_at,
            'orders_count' => $affiliate->orders_count ?? 0,
            'earnings_balance_sen' => $earningsBalanceSen,
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->status->value,
                'tier_id' => $subscription->affiliate_membership_tier_id,
                'tier_name' => $subscription->tier?->name,
                'monthly_fee_sen' => $subscription->tier?->monthly_fee_sen,
                'markup_percent' => $subscription->tier?->markup_percent,
                'current_period_started_at' => $subscription->current_period_started_at,
                'next_charge_at' => $subscription->next_charge_at,
                'grace_until' => $subscription->grace_until,
            ],
        ];
    }
}
