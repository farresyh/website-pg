<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\UpdateMembershipEnabledRequest;
use App\Http\Requests\Membership\UpdateMembershipPlanRequest;
use App\Models\MembershipPlan;
use App\Models\MembershipPlanChange;
use App\Models\PlatformSettings;
use Illuminate\Http\JsonResponse;

/**
 * ADR-027's 2026-08-29 addendum, decisions 14/15: /admin/membership's
 * backend — edit-only against the two fixed membership_plans rows, no
 * create/delete action exists. `super_admin` only, same tier as
 * Settings/Price Sync/SEO (platform-wide business config).
 */
class MembershipPlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(MembershipPlan::orderBy('id')->get());
    }

    /**
     * Decision 22: writes one membership_plan_changes row per field that
     * actually changed (mirrors price_change_logs/deactivation_logs'
     * "only real changes get logged" discipline) — never a blind diff-
     * less audit row.
     */
    public function update(UpdateMembershipPlanRequest $request, MembershipPlan $membershipPlan): JsonResponse
    {
        $original = $membershipPlan->only(['name', 'fee_sen', 'quota_sen', 'discount_percent']);

        $membershipPlan->update($request->validated());

        foreach ($original as $field => $oldValue) {
            $newValue = $membershipPlan->{$field};

            if ((string) $oldValue !== (string) $newValue) {
                MembershipPlanChange::create([
                    'membership_plan_id' => $membershipPlan->id,
                    'admin_user_id' => $request->user()->id,
                    'field_changed' => $field,
                    'old_value' => (string) $oldValue,
                    'new_value' => (string) $newValue,
                ]);
            }
        }

        return response()->json($membershipPlan);
    }

    /**
     * Decision 20's kill switch — lives on PlatformSettings, not
     * membership_plans, but exposed here since /admin/membership is
     * the screen that owns this feature's own on/off state.
     */
    public function updateEnabled(UpdateMembershipEnabledRequest $request): JsonResponse
    {
        $settings = PlatformSettings::current();
        $settings->update($request->validated());

        return response()->json($settings->only('membership_enabled'));
    }
}
