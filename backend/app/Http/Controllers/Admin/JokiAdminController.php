<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Joki\SaveJokiRouteRequest;
use App\Http\Requests\Admin\Joki\UpdateJokiSettingsRequest;
use App\Http\Requests\Admin\Joki\UpdateJokiTiersRequest;
use App\Models\JokiRoute;
use App\Models\JokiSetting;
use App\Models\JokiTier;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\WhatsAppGroup;
use App\Services\Joki\JokiBoard;
use App\Services\Joki\JokiCatalog;
use App\Services\Joki\JokiLadder;
use App\Services\Order\DeliveryStatus;
use App\Services\Report\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mews\Purifier\Facades\Purifier;

/**
 * ADR-126 decisions 23-24: Admin → Joki. Orders (JokiBoard's filters) and
 * Penjoki for every admin; Settings, the rank model and route packages
 * for the super admin. Assign and progress are JokiAssignmentController.
 * Recording a penjoki payment is the existing supplier-transfer action.
 */
class JokiAdminController extends Controller
{
    public function __construct(private readonly JokiBoard $board) {}

    public function orders(Request $request): JsonResponse
    {
        $request->validate([
            'filter' => ['nullable', Rule::in(JokiBoard::FILTERS)],
            'search' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // The same search and KL-day date bounds as /orders (OrderController::applyFilters).
        [$from, $toExclusive] = app(ReportService::class)->dateRangeFromDates($request->query('date_from'), $request->query('date_to'));
        $search = $request->query('search');

        $page = $this->board->orders($request->query('filter'))
            ->when($search, fn ($q) => $q->where(fn ($s) => $s->where('order_number', 'like', "%{$search}%")->orWhere('customer_email', 'like', "%{$search}%")))
            ->when($request->query('supplier_id'), fn ($q, $id) => $q->where('supplier_id', (int) $id))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($toExclusive, fn ($q) => $q->where('created_at', '<', $toExclusive))
            ->with(['jokiOrder', 'supplier', 'package'])
            ->oldest('paid_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Order $order) => $this->row($order));

        return response()->json($page);
    }

    public function summary(): JsonResponse
    {
        // The page's "All Joki" card adds the total to the Dashboard card's four.
        return response()->json([...$this->board->counts(), 'all' => $this->board->orders(null)->count()]);
    }

    /** Each penjoki's open orders and what the platform owes them (their pay-after funding ledger). */
    public function penjoki(): JsonResponse
    {
        $open = [DeliveryStatus::Pending->value, DeliveryStatus::NeedsReview->value];

        return response()->json(Supplier::query()
            ->where('joki_role', Supplier::JOKI_PENJOKI)
            ->select('suppliers.*')
            ->addSelect(['open_orders' => Order::query()->joki()->selectRaw('COUNT(*)')
                ->whereColumn('orders.supplier_id', 'suppliers.id')
                ->where('is_test', false)
                ->whereIn('delivery_status', $open)])
            ->addSelect(['has_group' => WhatsAppGroup::query()->selectRaw('COUNT(*) > 0')
                ->where('owner_type', WhatsAppGroup::OWNER_SUPPLIER)
                ->whereColumn('owner_id', 'suppliers.id')
                ->where('is_active', true)])
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'is_active' => $s->is_active,
                'has_group' => (bool) $s->has_group,
                'open_orders' => (int) $s->open_orders,
                // A pay-after ledger runs negative by what is owed.
                'owed_sen' => max(0, (int) round(-(float) $s->supplierLedgerBalance() * 100)),
            ]));
    }

    public function settings(JokiCatalog $catalog): JsonResponse
    {
        $ladder = JokiLadder::current();

        return response()->json([
            'settings' => JokiSetting::current(),
            'is_set_up' => $catalog->calculator() !== null,
            'tiers' => $ladder->tiers()->values(),
            'routes' => JokiRoute::query()->with('package:id,name,is_active')->orderBy('id')->get()->map(fn (JokiRoute $r) => [
                'id' => $r->id,
                'package_id' => $r->package_id,
                'name' => $r->package->name,
                'is_active' => $r->package->is_active,
                'from' => $ladder->position($r->fromRank(), 'from')->toArray(),
                'to' => $ladder->position($r->toRank(), 'to')->toArray(),
                'price_rm' => $r->price_rm,
                'is_quotation' => $r->is_quotation,
            ]),
        ]);
    }

    /** Switching joki on sets up its game and calculator (JokiCatalog::ensure()); off stops new sales only. */
    public function updateSettings(UpdateJokiSettingsRequest $request, JokiCatalog $catalog): JsonResponse
    {
        $data = $request->validated();
        $settings = JokiSetting::current();
        $after = array_merge($settings->only(['grading_price_rm', 'grading_express_price_rm', 'max_mythic_stars']), $data);

        if ($after['grading_express_price_rm'] < $after['grading_price_rm']) {
            throw ValidationException::withMessages(['grading_express_price_rm' => ['Grading Express can\'t be cheaper than Grading.']]);
        }

        $highestBand = (int) JokiTier::query()->max('mythic_from_stars');
        if ($after['max_mythic_stars'] <= $highestBand) {
            throw ValidationException::withMessages(['max_mythic_stars' => ["The Mythic star cap must be above {$highestBand}★, where the highest tier starts."]]);
        }

        if (array_key_exists('terms_html', $data) && $data['terms_html'] !== null) {
            $data['terms_html'] = Purifier::clean($data['terms_html'], 'rich_text');
        }

        if ($data['is_enabled'] ?? false) {
            $catalog->ensure();
        }

        $settings->update($data);

        return response()->json($settings->fresh());
    }

    /** Edits existing tiers only; the ladder and every route must still read after the change. */
    public function updateTiers(UpdateJokiTiersRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request) {
            foreach ($request->validated('tiers') as $row) {
                $tier = JokiTier::query()->lockForUpdate()->findOrFail($row['id']);
                $shape = $tier->isDivisional() ? ['divisions', 'stars_per_division'] : ['mythic_from_stars'];
                $tier->update(collect($row)->only(['name', 'rate_rm', 'express_rate_rm', 'icon_url', ...$shape])->all());
            }

            $this->assertLadderReads();
        });

        return response()->json(JokiLadder::current()->tiers()->values());
    }

    public function storeRoute(SaveJokiRouteRequest $request, JokiCatalog $catalog): JsonResponse
    {
        $data = $request->validated();
        $this->assertClimb($data['from'], $data['to']);
        $game = $catalog->ensure()->game;

        $route = DB::transaction(function () use ($data, $game) {
            $package = Package::query()->create([
                'game_id' => $game->id,
                'name' => $data['name'],
                'supplier_id' => Supplier::assignmentPool()->id,
                'supplier_package_ref' => 'JOKI-ROUTE-'.Str::upper(Str::random(8)),
                // F1: never read; the price is the route's.
                'cost_price' => 0,
                'standard_selling_price' => 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return JokiRoute::query()->create(['package_id' => $package->id, ...$this->routeColumns($data)]);
        });

        return response()->json(['id' => $route->id], 201);
    }

    /** A route is switched off, never deleted: past orders point at its package. */
    public function updateRoute(SaveJokiRouteRequest $request, JokiRoute $jokiRoute): JsonResponse
    {
        $route = $jokiRoute;
        $data = $request->validated();
        $this->assertClimb($data['from'] ?? $route->fromRank(), $data['to'] ?? $route->toRank());

        $quotation = (bool) ($data['is_quotation'] ?? $route->is_quotation);
        if (! $quotation && ($data['price_rm'] ?? $route->price_rm) === null) {
            throw ValidationException::withMessages(['price_rm' => ['Set a price, or make the route a quotation.']]);
        }

        DB::transaction(function () use ($data, $route) {
            $route->update($this->routeColumns($data + ['is_quotation' => $route->is_quotation]));
            $route->package->update(array_intersect_key($data, array_flip(['name', 'is_active'])));
        });

        return response()->json(['id' => $route->id]);
    }

    /** @return array<string, mixed> */
    private function routeColumns(array $data): array
    {
        $columns = [];
        foreach (['from', 'to'] as $end) {
            if (isset($data[$end])) {
                $columns["{$end}_tier_id"] = (int) $data[$end]['tier_id'];
                $columns["{$end}_division"] = $data[$end]['division'] ?? null;
                $columns["{$end}_stars"] = $data[$end]['stars'] ?? null;
            }
        }

        $quotation = (bool) ($data['is_quotation'] ?? false);
        $columns['is_quotation'] = $quotation;
        if ($quotation) {
            $columns['price_rm'] = null;
        } elseif (array_key_exists('price_rm', $data)) {
            $columns['price_rm'] = $data['price_rm'];
        }

        return $columns;
    }

    private function assertClimb(array $from, array $to): void
    {
        $ladder = JokiLadder::current();

        if ($ladder->position($to, 'to')->index <= $ladder->position($from, 'from')->index) {
            throw ValidationException::withMessages(['to' => ['The target rank must be above the starting rank.']]);
        }
    }

    /** The Mythic bands start at 0 and climb; every route still names real ranks in order. */
    private function assertLadderReads(): void
    {
        $bands = JokiTier::query()->where('kind', JokiTier::KIND_MYTHIC)->orderBy('sort_order')->pluck('mythic_from_stars')->all();
        $climbs = $bands === [] || $bands[0] !== 0 || collect($bands)->sliding(2)->contains(fn ($pair) => $pair->last() <= $pair->first());
        if ($climbs) {
            throw ValidationException::withMessages(['tiers' => ['Mythic tiers must start at 0★ and each start above the one before.']]);
        }

        if ((int) max($bands) >= JokiSetting::current()->max_mythic_stars) {
            throw ValidationException::withMessages(['tiers' => ['A Mythic tier starts above the Mythic star cap.']]);
        }

        foreach (JokiRoute::query()->with('package:id,name')->get() as $route) {
            try {
                $this->assertClimb($route->fromRank(), $route->toRank());
            } catch (ValidationException) {
                throw ValidationException::withMessages(['tiers' => ["Route \"{$route->package->name}\" no longer fits this rank model."]]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function row(Order $order): array
    {
        $joki = $order->jokiOrder;
        $pending = $order->delivery_status === DeliveryStatus::Pending;
        $supplier = $order->supplier;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'delivery_status' => $order->delivery_status->value,
            'paid_at' => $order->paid_at?->toISOString(),
            'supplier_id' => $order->supplier_id,
            'penjoki' => $supplier?->isPenjoki() ? $supplier->name : null,
            'from_rank' => $joki?->from_rank['label'] ?? null,
            'to_rank' => $joki?->to_rank['label'] ?? null,
            'current_rank' => $joki?->current_rank['label'] ?? null,
            'is_express' => (bool) $joki?->is_express,
            'role' => $joki?->role,
            'hero_request' => $joki?->hero_request,
            'play_window' => $joki?->play_window,
            'selling_price' => $order->selling_price,
            'payout_sen' => $order->cost_price,
            'last_activity_at' => $pending ? $order->pendingClock()->toISOString() : null,
            'sla_due_at' => $pending
                ? $order->pendingClock()->copy()->addMinutes(($order->fulfilmentSupplier()?->pendingPolicy() ?? Supplier::defaultPendingPolicy())['stale_minutes'])->toISOString()
                : null,
            'is_stale' => $order->delivery_status === DeliveryStatus::NeedsReview || $order->isStalePending(),
        ];
    }
}
