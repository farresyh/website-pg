<?php

namespace Database\Seeders\FinancialScenario;

use App\Models\Order;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Support\Str;

/**
 * ADR-127 decision 12: the API supplier the financial scenario drives.
 * Zero network. By default every order succeeds at its own catalog cost,
 * returned in ringgit the way a real MYR supplier echoes `price`, so the
 * real-cost path (ADR-111) runs exactly as in production. A scenario
 * queues a different outcome for the next order when it needs one.
 */
final class ScriptedSupplierAdapter implements SupplierAdapter
{
    /** @var list<SupplierResponse|int> */
    private array $queue = [];

    /**
     * @param  (\Closure(int): float)|null  $toSupplierPrice  ringgit sen to the supplier's own currency
     *                                                        (an IDR supplier echoes rupiah); null = MYR
     */
    public function __construct(private readonly ?\Closure $toSupplierPrice = null) {}

    /** The next order fails, confirmed (Digiflazz-style), so it lands on Failed. */
    public function failNext(): void
    {
        $this->queue[] = SupplierResponse::failure('confirmed_failed', 'Scenario: supplier refused', resendUnsafeWithSameReference: true, outcomeConfirmedFailed: true);
    }

    /** The next order is accepted but not yet done; `FinancialScenario::supplierDelivers()` finishes it later. */
    public function pendingNext(): void
    {
        $this->queue[] = SupplierResponse::pending(['supplier_ref' => 'SCN-'.Str::upper(Str::random(10))]);
    }

    /** The next order's outcome is unknown and unsafe to resubmit (Gamevion-style), so it lands on NeedsReview. */
    public function ambiguousNext(): void
    {
        $this->queue[] = SupplierResponse::failure('duplicate_reference', 'Scenario: outcome unknown', resendUnsafeWithSameReference: true);
    }

    /** The next order succeeds at a real cost other than its catalog cost (sen). */
    public function costNext(int $realCostSen): void
    {
        $this->queue[] = $realCostSen;
    }

    public function createOrder(SupplierOrderRequest $request): SupplierResponse
    {
        $next = array_shift($this->queue);

        if ($next instanceof SupplierResponse) {
            return $next;
        }

        $sen = $next ?? Order::query()->findOrFail($request->orderId)->cost_price;

        return SupplierResponse::success([
            'supplier_ref' => 'SCN-'.Str::upper(Str::random(10)),
            'price' => $this->price($sen),
            'player_id' => $request->playerId,
            'server_id' => $request->serverId,
        ]);
    }

    /** What the supplier charges, in its own currency, for a ringgit cost. */
    public function price(int $sen): float
    {
        return $this->toSupplierPrice === null ? $sen / 100 : ($this->toSupplierPrice)($sen);
    }

    public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
    {
        return SupplierResponse::success(['supplier_ref' => $request->supplierRef, 'status' => 'delivered']);
    }

    public function checkBalance(): SupplierResponse
    {
        return SupplierResponse::success(['balance' => null]);
    }

    public function listProducts(): SupplierResponse
    {
        return SupplierResponse::success([]);
    }

    public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
    {
        throw new ValidationNotSupportedException('The scenario supplier has no player validation.');
    }
}
