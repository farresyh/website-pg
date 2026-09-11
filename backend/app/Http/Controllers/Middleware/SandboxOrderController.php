<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Requests\MarkOrderDeliveredRequest;
use App\Http\Requests\Middleware\CreateSandboxOrderRequest;
use App\Http\Requests\Middleware\ResendSandboxOrderDeliveryRequest;
use App\Models\Affiliate;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Fulfillment\OrderResendService;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderNumberService;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Order\ReferenceNumberService;
use App\Services\Pricing\MembershipPricingService;
use App\Services\Pricing\PricingService;
use App\Services\Supplier\FakeSupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Voucher\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ADR-018: a middleware-only tool for exercising the real Order
 * lifecycle (status transitions, OrderResendService's validation
 * logic, order_resend_attempts audit trail, UI) without touching real
 * data or real money. Every query here is unconditionally scoped to
 * is_test = true — the mirror image of Admin\OrderController's own
 * permanent is_test = false scope (decision #2). Payment is skipped
 * entirely (decision #3), delivery never calls the real Gamevion
 * adapter (decision #5), and creditProfit() is guarded against sandbox
 * orders at the source (decision #6) — see docs/adr.md.
 *
 * ADR-026: resend()'s `error_code` field is free-text, so typing
 * `duplicate_reference` reaches DeliveryStatus::NeedsReview through
 * the exact same OrderFulfillmentService::fulfill() logic a real
 * ambiguous Gamevion response would — no sandbox-specific wiring
 * needed for that transition. markDelivered() below is the sandbox
 * counterpart to Admin\OrderController::markDelivered() (ADR-026
 * decision 4a), letting that resolution path be exercised here too.
 */
class SandboxOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::query()->where('is_test', true)->with(['game:id,name', 'package:id,name']);

        if ($search = $request->query('search')) {
            $query->where('order_number', 'like', "%{$search}%");
        }

        $perPage = (int) $request->query('per_page', 25);

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate($perPage)->withQueryString(),
        );
    }

    public function show(Order $order): JsonResponse
    {
        $this->assertIsSandboxOrder($order);

        return response()->json($order->load([
            'game', 'package', 'supplier', 'affiliate',
            'resendAttempts' => fn ($query) => $query->with('package:id,name')->latest(),
        ]));
    }

    /**
     * Decision #3: skips the payment gateway entirely — payment_status is paid
     * immediately. Decision #4: created directly at delivery_status =
     * failed, so the freshly created order is immediately usable with
     * the same Resend Delivery flow a real failed order would use.
     */
    public function store(CreateSandboxOrderRequest $request): JsonResponse
    {
        $game = Game::query()->findOrFail($request->validated('game_id'));
        $package = Package::query()->findOrFail($request->validated('package_id'));

        if ($package->game_id !== $game->id) {
            throw ValidationException::withMessages([
                'package_id' => ['The selected package must belong to the selected game.'],
            ]);
        }

        if (! $package->is_active) {
            throw ValidationException::withMessages([
                'package_id' => ['This package is not currently active.'],
            ]);
        }

        $affiliate = Affiliate::primary();
        $pricing = app(PricingService::class)->calculate(
            $package->cost_price,
            $package->standard_selling_price,
            (float) $affiliate->markup_pct,
        );

        $order = Order::query()->create([
            'order_number' => app(OrderNumberService::class)->generate(),
            'is_test' => true,
            'customer_email' => $request->validated('customer_email') ?: 'sandbox@example.test',
            'customer_name' => $request->validated('customer_name') ?: 'Sandbox Tester',
            'customer_phone' => $request->validated('customer_phone') ?: '0100000000',
            'player_id' => $request->validated('player_id') ?: 'sandbox-player',
            'server_id' => $request->validated('server_id'),
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $package->supplier_id,
            'supplier_product_ref' => $package->supplier_package_ref,
            'affiliate_id' => $affiliate->id,
            'cost_price' => $pricing->costPrice,
            'standard_selling_price' => $pricing->standardSellingPrice,
            'affiliate_markup_pct' => $affiliate->markup_pct,
            'selling_price' => $pricing->sellingPrice,
            'transaction_fee' => 0,
            'final_amount' => $pricing->sellingPrice,
            'platform_profit' => $pricing->platformProfit,
            'affiliate_profit' => $pricing->affiliateProfit,
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
            'delivery_status' => DeliveryStatus::Failed->value,
            'payment_method' => 'sandbox',
        ]);

        return response()->json($order, 201);
    }

    /**
     * Decision #5: synchronous, inline, no queue dispatch — a sandbox
     * order never depends on a queue worker actually running. Builds
     * its own OrderResendService/OrderFulfillmentService instance
     * directly, binding FakeSupplierAdapter with the outcome the admin
     * picked under the target package's own real supplier slug (ADR-031:
     * OrderResendService moves the order onto that supplier before
     * fulfill() ever runs) rather than resolving through whatever real
     * adapter is normally bound for that slug (GamevionAdapter/
     * DigiflazzAdapter, untouched, for the real Admin\OrderController
     * path).
     */
    public function resend(ResendSandboxOrderDeliveryRequest $request, Order $order): JsonResponse
    {
        $this->assertIsSandboxOrder($order);

        $targetPackage = Package::query()->findOrFail($request->validated('package_id'));

        $adapter = new FakeSupplierAdapter(
            $request->boolean('simulate_success'),
            $request->validated('error_code'),
            $request->validated('error_message'),
        );

        app()->bind("supplier-adapter.{$targetPackage->supplier->slug}", fn () => $adapter);

        $fulfillment = new OrderFulfillmentService(
            new OrderStatusService,
            new ReferenceNumberService,
            app(SupplierAdapterFactory::class),
            app(LedgerService::class),
            app(VoucherService::class),
            app(SupplierFundingService::class),
        );

        $resend = new OrderResendService($fulfillment, app(PricingService::class), app(MembershipPricingService::class));

        $result = $resend->resend(
            $order,
            $targetPackage,
            $request->validated('note'),
            $request->user()?->name,
        );

        return response()->json($result->fresh(['game', 'package', 'supplier', 'affiliate', 'resendAttempts']));
    }

    /**
     * ADR-026 decision 4a's sandbox counterpart — same
     * OrderFulfillmentService::markDeliveredManually() a real
     * needs_review order uses, so creditProfit()'s existing decision
     * #6 is_test guard applies here unchanged (no new guard needed to
     * keep this off the real ledger). No voucher-exists check, unlike
     * the real controller's — sandbox has no voucher feature at all.
     */
    public function markDelivered(MarkOrderDeliveredRequest $request, Order $order, OrderFulfillmentService $fulfillment): JsonResponse
    {
        $this->assertIsSandboxOrder($order);

        if ($order->delivery_status !== DeliveryStatus::NeedsReview) {
            throw ValidationException::withMessages([
                'delivery_status' => ['Only a test order in needs-review can be marked delivered.'],
            ]);
        }

        $result = $fulfillment->markDeliveredManually(
            $order,
            $request->validated('supplier_ref'),
            $request->validated('note'),
            $request->user()?->name ?? 'Sandbox Tester',
        );

        return response()->json($result->fresh(['game', 'package', 'supplier', 'affiliate', 'resendAttempts']));
    }

    /**
     * Decision #7: a real delete — safe with no residue precisely
     * because decision #6 means a sandbox order never had a real
     * LedgerEntry to leave behind. order_resend_attempts cascade-
     * deletes via its existing FK.
     */
    public function destroy(Order $order): JsonResponse
    {
        $this->assertIsSandboxOrder($order);

        $order->delete();

        return response()->json(['message' => 'Test order deleted.']);
    }

    public function destroyAll(): JsonResponse
    {
        $count = Order::query()->where('is_test', true)->count();
        Order::query()->where('is_test', true)->delete();

        return response()->json(['message' => "Deleted {$count} test order(s)."]);
    }

    /**
     * Route-model binding alone can't scope by is_test (it only knows
     * the primary key) — this is the explicit, easy-to-audit guard
     * that stops a real order id from ever being read/mutated through
     * this controller, the mirror image of Admin\OrderController's own
     * permanent is_test = false query scope.
     */
    private function assertIsSandboxOrder(Order $order): void
    {
        if (! $order->is_test) {
            abort(404);
        }
    }
}
