<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaign\CampaignMarginCheckRequest;
use App\Http\Requests\Campaign\StoreCampaignFeeRequest;
use App\Http\Requests\Campaign\StoreCampaignRequest;
use App\Http\Requests\Campaign\StorePromoCodeRequest;
use App\Http\Requests\Campaign\UpdateCampaignFeeRequest;
use App\Http\Requests\Campaign\UpdateCampaignRequest;
use App\Http\Requests\Campaign\UpdateCampaignSettingsRequest;
use App\Http\Requests\Campaign\UpdatePromoCodeRequest;
use App\Models\Affiliate;
use App\Models\Campaign;
use App\Models\CampaignFee;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Models\PromoCode;
use App\Services\Campaign\CampaignFeeSchedule;
use App\Services\Campaign\CampaignService;
use App\Services\Pricing\OrderPricingResolver;
use App\Services\Report\ReportService;
use App\Support\MalaysiaTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ADR-118 V15/V17/V19, PR 4: the admin Campaigns page. Money rules live in
 * CampaignFeeSchedule (fees, closed months) and CampaignService (the
 * margin floor); the scorecard is ReportService::campaignBreakdown(), so
 * its profit is the ledger's, as on Reports.
 */
class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignFeeSchedule $fees,
        private readonly CampaignService $campaigns,
        private readonly ReportService $reports,
    ) {}

    /** The list with its scorecard: the chosen range beside the cumulative figures. */
    public function index(Request $request): JsonResponse
    {
        [$range, $cumulative] = $this->scorecards($request);
        $settings = PlatformSettings::current();

        $campaigns = Campaign::query()->with(['fees', 'promoCodes:id,campaign_id,code,is_active'])->orderByDesc('id')->get()
            ->map(fn (Campaign $c) => [
                ...$this->summary($c),
                'range' => $range[$c->id],
                'cumulative' => $cumulative[$c->id],
                'verdict' => $this->verdict($c, $cumulative[$c->id], $settings),
            ]);

        return response()->json(['campaigns' => $campaigns, 'settings' => $this->settings($settings)]);
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $data = $request->validated();
        $campaign = $this->fees->createCampaign($data['name'], $data['monthly_fee_sen'], $data['from_month'], $data['to_month'] ?? null, $request->user()->id);

        return response()->json(['id' => $campaign->id], 201);
    }

    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        [$range, $cumulative] = $this->scorecards($request);
        $campaign->load(['fees', 'promoCodes' => fn ($q) => $q->withCount('redemptions')->orderBy('id'), 'affiliate']);
        $month = Carbon::now(MalaysiaTime::TIMEZONE)->format('Y-m');
        $origin = $campaign->affiliate->canonicalOrigin();

        return response()->json([
            ...$this->summary($campaign),
            'fees' => $campaign->fees->map(fn (CampaignFee $f) => [
                'id' => $f->id,
                'monthly_fee_sen' => $f->monthly_fee_sen,
                'from_month' => $f->from_month->format('Y-m'),
                'to_month' => $f->to_month?->format('Y-m'),
            ]),
            'balance' => [
                'month' => $month,
                'accrued_sen' => $this->fees->accruedThroughSen($campaign, $month),
                'paid_sen' => $this->fees->paidThroughSen($campaign, Carbon::now(MalaysiaTime::TIMEZONE)->endOfMonth()),
                'balance_sen' => $this->fees->balanceSen($campaign, $month),
            ],
            'codes' => $campaign->promoCodes->map(fn (PromoCode $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'discount_percent' => (float) $p->discount_percent,
                'max_discount_sen' => $p->max_discount_sen,
                'budget_sen' => $p->budget_sen,
                'used_sen' => $this->campaigns->usedSen($p),
                'is_active' => $p->is_active,
                'is_redeemable_now' => $p->isRedeemableNow(),
                'starts_at' => $p->starts_at?->toIso8601String(),
                'ends_at' => $p->ends_at?->toIso8601String(),
                // V17: the percentage is fixed once a redemption exists.
                'has_redemptions' => $p->redemptions_count > 0,
                'over_cap' => $p->redemptions()->where('over_cap', true)->exists(),
                'tracker_url' => "{$origin}/?promo={$p->code}",
            ]),
            'range' => $range[$campaign->id],
            'cumulative' => $cumulative[$campaign->id],
            'verdict' => $this->verdict($campaign, $cumulative[$campaign->id], PlatformSettings::current()),
        ]);
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $campaign->name = $data['name'];
        }

        if (array_key_exists('ended', $data)) {
            $campaign->ended_at = $data['ended'] ? ($campaign->ended_at ?? now()) : null;
        }

        $campaign->save();

        return response()->json($this->summary($campaign->load('fees', 'promoCodes:id,campaign_id,code,is_active')));
    }

    public function storeFee(StoreCampaignFeeRequest $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validated();
        $fee = $this->fees->addFee($campaign, $data['monthly_fee_sen'], $data['from_month'], $data['to_month'] ?? null, $request->user()->id);

        return response()->json(['id' => $fee->id], 201);
    }

    public function updateFee(UpdateCampaignFeeRequest $request, CampaignFee $campaignFee): JsonResponse
    {
        $this->fees->setEndMonth($campaignFee, $request->validated('to_month'));

        return response()->json(['id' => $campaignFee->id]);
    }

    public function storeCode(StorePromoCodeRequest $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validated();
        $this->assertWindow($data['starts_at'] ?? null, $data['ends_at'] ?? null);

        $code = $campaign->promoCodes()->create([...$data, 'is_active' => $data['is_active'] ?? true]);

        return response()->json(['id' => $code->id, 'code' => $code->code], 201);
    }

    public function updateCode(UpdatePromoCodeRequest $request, PromoCode $promoCode): JsonResponse
    {
        $data = $request->validated();

        // V17: a new offer is a new code, so the scorecard never mixes two offers.
        if (array_key_exists('discount_percent', $data)
            && (float) $data['discount_percent'] !== (float) $promoCode->discount_percent
            && $promoCode->redemptions()->exists()) {
            throw ValidationException::withMessages(['discount_percent' => ['This code has been redeemed, so its percentage is fixed. Create a new code for a new offer.']]);
        }

        $this->assertWindow(
            array_key_exists('starts_at', $data) ? $data['starts_at'] : $promoCode->starts_at?->toIso8601String(),
            array_key_exists('ends_at', $data) ? $data['ends_at'] : $promoCode->ends_at?->toIso8601String(),
        );

        $promoCode->update($data);

        return response()->json(['id' => $promoCode->id]);
    }

    /** V10: the packages this percentage (and per-order cap) would refuse under the margin floor. */
    public function marginCheck(CampaignMarginCheckRequest $request, OrderPricingResolver $resolver): JsonResponse
    {
        $data = $request->validated();
        // An unsaved code: id 0 only so the quote can be built; nothing is stored.
        $probe = (new PromoCode(['discount_percent' => $data['discount_percent'], 'max_discount_sen' => $data['max_discount_sen'] ?? null]))->forceFill(['id' => 0]);
        $terms = Affiliate::primary()->storefrontPriceTerms();

        $rejected = Package::query()
            ->where('is_active', true)
            ->whereHas('game', fn ($q) => $q->where('is_active', true)->where('is_joki', false))
            ->with('game:id,name')
            ->orderBy('game_id')->orderBy('standard_selling_price')
            ->get()
            ->filter(fn (Package $p) => $this->campaigns->refusedOn($probe, $resolver->resolveStorefront(
                $p->cost_price, $p->standard_selling_price, (float) $p->markup_percent, $terms['markup'], $terms['tier'], null,
            )))
            ->map(fn (Package $p) => ['package_id' => $p->id, 'game_name' => $p->game->name, 'package_name' => $p->name, 'price_sen' => $p->standard_selling_price])
            ->values();

        return response()->json(['rejected' => $rejected]);
    }

    public function updateSettings(UpdateCampaignSettingsRequest $request): JsonResponse
    {
        $settings = PlatformSettings::current();
        $settings->update($request->validated());

        return response()->json($this->settings($settings->fresh()));
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    private function scorecards(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        [$from, $toExclusive] = $this->reports->dateRangeFromDates($request->query('from'), $request->query('to'));

        return [$this->reports->campaignBreakdown($from, $toExclusive), $this->reports->campaignBreakdown(null, null)];
    }

    /** @return array<string, mixed> */
    private function summary(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'ended_at' => $campaign->ended_at?->toIso8601String(),
            'started_month' => $campaign->fees->first()?->from_month->format('Y-m'),
            'monthly_fee_sen' => $this->fees->feeForMonth($campaign, Carbon::now(MalaysiaTime::TIMEZONE)->format('Y-m')),
            'codes' => $campaign->promoCodes->pluck('code'),
        ];
    }

    /**
     * The founder's rule (PR 4): no verdict until a target CAC and a trial
     * length are set; during the trial, which month it is; after it, the
     * cumulative CAC against the target. No qualified customer after the
     * trial is above target.
     *
     * @return array{status: string, month?: int, of?: int}|null
     */
    private function verdict(Campaign $campaign, array $cumulative, PlatformSettings $settings): ?array
    {
        $first = $campaign->fees->first()?->from_month;

        if ($settings->campaign_target_cac_sen === null || $settings->campaign_trial_months === null || $first === null) {
            return null;
        }

        $now = Carbon::now(MalaysiaTime::TIMEZONE);
        $month = ($now->year * 12 + $now->month) - ($first->year * 12 + $first->month) + 1;

        if ($month <= $settings->campaign_trial_months) {
            return ['status' => 'in_trial', 'month' => max(1, $month), 'of' => $settings->campaign_trial_months];
        }

        $cac = $cumulative['cac_sen'];

        return ['status' => $cac !== null && $cac <= $settings->campaign_target_cac_sen ? 'worth_keeping' : 'above_target'];
    }

    /** @return array<string, mixed> */
    private function settings(PlatformSettings $settings): array
    {
        return [
            'campaign_min_margin_percent' => (float) $settings->campaign_min_margin_percent,
            'campaign_qualified_min_sen' => $settings->campaign_qualified_min_sen,
            'campaign_target_cac_sen' => $settings->campaign_target_cac_sen,
            'campaign_trial_months' => $settings->campaign_trial_months,
        ];
    }

    private function assertWindow(?string $startsAt, ?string $endsAt): void
    {
        if ($startsAt !== null && $endsAt !== null && Carbon::parse($endsAt)->lte(Carbon::parse($startsAt))) {
            throw ValidationException::withMessages(['ends_at' => ['The end must be after the start.']]);
        }
    }
}
