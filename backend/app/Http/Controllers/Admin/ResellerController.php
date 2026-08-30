<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignResellerTierRequest;
use App\Http\Requests\Admin\StoreResellerRequest;
use App\Http\Requests\Admin\StoreResellerUserRequest;
use App\Http\Requests\Admin\UpdateResellerRequest;
use App\Http\Requests\Admin\UpdateResellerStatusRequest;
use App\Models\Reseller;
use App\Models\ResellerImpersonationSession;
use App\Models\ResellerMembershipTier;
use App\Models\ResellerUser;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerInviteService;
use App\Services\Reseller\ResellerSubscriptionService;
use App\Services\Reseller\ResellerTierFeeService;
use App\Services\Withdrawal\WithdrawalStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ADR-058 58b (RES-1..3, RES-5, RES-6): super_admin-only Reseller
 * Management. Same role tier as Settings / Blacklist / Membership —
 * route-level `admin.role:super_admin` is the gate, this controller
 * assumes it already ran.
 *
 * Impersonation (RES-4) lives in ResellerImpersonationController; the
 * `reseller_membership_tiers` CRUD lives in
 * ResellerMembershipTierController. Reseller balances are always derived
 * from the ledger (ADR-002) — there is no balance column.
 */
class ResellerController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ResellerSubscriptionService $subscriptions,
        private readonly ResellerInviteService $invites,
        private readonly ResellerTierFeeService $tierFees,
    ) {}

    /**
     * RES-1: the reseller table. Earnings balance is batched into one
     * grouped ledger query, not one per row.
     */
    public function index(): JsonResponse
    {
        $resellers = Reseller::query()
            ->with(['subscription.tier' => fn ($q) => $q->withTrashed()])
            ->withCount('orders')
            ->orderBy('business_name')
            ->get();

        $balances = $this->ledger->balances('reseller', $resellers->pluck('id')->all());

        return response()->json([
            'resellers' => $resellers->map(fn (Reseller $r) => $this->rowShape($r, $balances[$r->id] ?? 0))->all(),
        ]);
    }

    public function show(Reseller $reseller): JsonResponse
    {
        return response()->json($this->detailShape($reseller));
    }

    /**
     * RES-2: create the Reseller row + its first reseller_user (null
     * password → set-password invite), optionally assign an initial
     * wholesale tier. The user create + invite send are wrapped in the
     * same transaction as the reseller create; a Plunk failure rolls the
     * whole thing back so the admin retries cleanly rather than being
     * left with a half-created reseller.
     */
    public function store(StoreResellerRequest $request): JsonResponse
    {
        $data = $request->validated();

        $reseller = DB::transaction(function () use ($data, $request) {
            $reseller = Reseller::query()->create([
                'business_name' => $data['business_name'],
                'contact_name' => $data['contact_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'markup_pct' => $data['markup_pct'],
                'max_markup_pct' => $data['max_markup_pct'] ?? null,
                'domains' => $data['domains'] ?? null,
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
            ]);

            $user = ResellerUser::query()->create([
                'reseller_id' => $reseller->id,
                'name' => $data['user_name'],
                'email' => $data['user_email'],
                'password' => null,
                'is_active' => true,
            ]);

            if (! empty($data['tier_id'])) {
                $tier = ResellerMembershipTier::query()->findOrFail($data['tier_id']);
                $this->subscriptions->assignTier($reseller, $tier, $request->user()->id, 'Initial tier (RES-2)');
            }

            $this->invites->sendInvite($user);

            return $reseller;
        });

        Log::info('Reseller created', [
            'reseller_id' => $reseller->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json($this->detailShape($reseller->fresh()), 201);
    }

    /** RES-3: edit business details + markup ceiling. */
    public function update(UpdateResellerRequest $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validated();

        $reseller->update([
            'business_name' => $data['business_name'],
            'contact_name' => $data['contact_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'markup_pct' => $data['markup_pct'],
            'max_markup_pct' => $data['max_markup_pct'] ?? null,
            'domains' => $data['domains'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json($this->detailShape($reseller->fresh()));
    }

    /** RES-3 / ADR-056 decision 8: assign or change the wholesale tier. */
    public function assignTier(AssignResellerTierRequest $request, Reseller $reseller): JsonResponse
    {
        $tier = ResellerMembershipTier::query()->findOrFail($request->validated('tier_id'));

        $this->subscriptions->assignTier(
            $reseller,
            $tier,
            $request->user()->id,
            $request->validated('note'),
        );

        return response()->json($this->detailShape($reseller->fresh()));
    }

    /**
     * ADR-056 decision 6: trigger this reseller's tier-fee charge now
     * instead of waiting for the scheduled run — debits earnings, or
     * starts/continues grace if earnings are short.
     */
    public function chargeTierFee(Reseller $reseller): JsonResponse
    {
        $subscription = $reseller->subscription;

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'tier' => ['This reseller has no wholesale-tier subscription to charge.'],
            ]);
        }

        $this->tierFees->chargeCycle($subscription);

        return response()->json($this->detailShape($reseller->fresh()));
    }

    /**
     * ADR-056 decision 3/6: reactivation is an explicit admin action.
     * Brings a grace/lapsed subscription back to active with a fresh
     * 30-day cycle (does not itself charge the fee).
     */
    public function reactivateSubscription(Reseller $reseller): JsonResponse
    {
        $subscription = $reseller->subscription;

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'tier' => ['This reseller has no wholesale-tier subscription to reactivate.'],
            ]);
        }

        $this->subscriptions->reactivate($subscription);

        return response()->json($this->detailShape($reseller->fresh()));
    }

    /** Add another staff login to an existing reseller (+ invite). */
    public function storeUser(StoreResellerUserRequest $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $reseller) {
            $user = ResellerUser::query()->create([
                'reseller_id' => $reseller->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => null,
                'is_active' => true,
            ]);

            $this->invites->sendInvite($user);
        });

        return response()->json($this->detailShape($reseller->fresh()));
    }

    /** Re-send the set-password invite for a user who hasn't accepted yet. */
    public function resendInvite(Reseller $reseller, ResellerUser $resellerUser): JsonResponse
    {
        abort_unless($resellerUser->reseller_id === $reseller->id, 404);

        if ($resellerUser->password !== null) {
            throw ValidationException::withMessages([
                'user' => ['This user has already set their password.'],
            ]);
        }

        $this->invites->sendInvite($resellerUser);

        return response()->json(['message' => 'Invite re-sent.']);
    }

    /** RES-5: activate / deactivate. Deactivating ends any live impersonation. */
    public function updateStatus(UpdateResellerStatusRequest $request, Reseller $reseller): JsonResponse
    {
        $status = $request->validated('status');
        $reseller->update(['status' => $status]);

        if ($status === 'inactive') {
            $this->endImpersonationSessions($reseller, 'reseller_deactivated');
        }

        Log::info('Reseller status changed', [
            'reseller_id' => $reseller->id,
            'status' => $status,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json($this->detailShape($reseller->fresh()));
    }

    /**
     * RES-6: soft-delete only, and only when there is nothing owed — the
     * earnings balance is exactly zero and no withdrawal is pending or
     * approved-but-uncompleted. The Cloudflare custom-hostname teardown
     * is ADR-060, not wired here.
     */
    public function destroy(Reseller $reseller): JsonResponse
    {
        if ($reseller->business_name === 'Platform Owner') {
            throw ValidationException::withMessages([
                'reseller' => ['The platform owner reseller cannot be deleted.'],
            ]);
        }

        $balance = $this->ledger->balance('reseller', $reseller->id);
        if ($balance !== 0) {
            throw ValidationException::withMessages([
                'reseller' => ["This reseller has a non-zero earnings balance ({$balance} sen). Settle it before deleting."],
            ]);
        }

        $hasOpenWithdrawal = DB::table('withdrawals')
            ->where('owner_type', 'reseller')
            ->where('owner_id', $reseller->id)
            ->whereIn('status', [WithdrawalStatus::Pending->value, WithdrawalStatus::Approved->value])
            ->exists();

        if ($hasOpenWithdrawal) {
            throw ValidationException::withMessages([
                'reseller' => ['This reseller has a pending or approved withdrawal. Complete or reject it before deleting.'],
            ]);
        }

        $this->endImpersonationSessions($reseller, 'reseller_deleted');

        $reseller->update(['status' => 'inactive']);
        $reseller->delete();

        Log::info('Reseller soft-deleted', ['reseller_id' => $reseller->id]);

        return response()->json(['message' => 'Reseller deleted.']);
    }

    private function endImpersonationSessions(Reseller $reseller, string $reason): void
    {
        $sessions = ResellerImpersonationSession::query()
            ->where('reseller_id', $reseller->id)
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
    private function detailShape(Reseller $reseller): array
    {
        $reseller->load([
            'users',
            'tierChanges.admin:id,name',
            'subscription.tier' => fn ($q) => $q->withTrashed(),
            'tierChanges.fromTier' => fn ($q) => $q->withTrashed(),
            'tierChanges.toTier' => fn ($q) => $q->withTrashed(),
        ]);
        $reseller->loadCount('orders');

        return [
            'reseller' => $this->rowShape($reseller, $this->ledger->balance('reseller', $reseller->id)),
            'users' => $reseller->users->map(fn (ResellerUser $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => $u->is_active,
                'last_login_at' => $u->last_login_at,
                'invite_pending' => $u->password === null,
            ])->all(),
            'tier_changes' => $reseller->tierChanges->sortByDesc('created_at')->values()->map(fn ($c) => [
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
    private function rowShape(Reseller $reseller, int $earningsBalanceSen): array
    {
        $subscription = $reseller->subscription;

        return [
            'id' => $reseller->id,
            'business_name' => $reseller->business_name,
            'contact_name' => $reseller->contact_name,
            'email' => $reseller->email,
            'phone' => $reseller->phone,
            'markup_pct' => $reseller->markup_pct,
            'max_markup_pct' => $reseller->max_markup_pct,
            'domains' => $reseller->domains ?? [],
            'status' => $reseller->status,
            'notes' => $reseller->notes,
            'is_platform_owner' => $reseller->business_name === 'Platform Owner',
            'deleted_at' => $reseller->deleted_at,
            'orders_count' => $reseller->orders_count ?? 0,
            'earnings_balance_sen' => $earningsBalanceSen,
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->status->value,
                'tier_id' => $subscription->reseller_membership_tier_id,
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
