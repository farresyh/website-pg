<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReplaceOrderCredentialRequest;
use App\Http\Requests\Admin\RevealOrderCredentialRequest;
use App\Models\CredentialReveal;
use App\Models\Order;
use App\Models\OrderCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ADR-124 decisions 5 and 6: a super_admin reveals or replaces one secret
 * field of a customer's game login, behind MFA step-up (routes/api.php,
 * pinned in AdminMfaStepUpTest). Every call that succeeds writes a
 * `credential_reveals` row first, so no value leaves without its audit
 * row. Sandbox orders are refused, like OrderController::show().
 */
class OrderCredentialController extends Controller
{
    public function reveal(RevealOrderCredentialRequest $request, Order $order): JsonResponse
    {
        $credential = $this->credentialFor($order);
        $field = $request->validated('field');
        abort_unless($credential->hasSecretField($field), 422, 'Not a secret field of this login.');

        $value = $credential->purged_at === null ? ($credential->secrets[$field] ?? null) : null;
        if ($value === null) {
            return response()->json(['message' => 'This login has been wiped. Ask the customer to send it again.'], 410);
        }

        $this->audit($request, $order, CredentialReveal::ACTION_REVEAL, $field);

        return response()
            ->json(['field' => $field, 'value' => $value])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Allowed while the order is open, wiped or not: an open order past
     * the D ceiling is how a re-sent login gets back in, and replacing
     * restarts D (2026-10-18 addendum, decision 4). A closed order needs
     * no login, so it is refused.
     */
    public function replace(ReplaceOrderCredentialRequest $request, Order $order): JsonResponse
    {
        $field = $request->validated('field');

        $summary = DB::transaction(function () use ($request, $order, $field) {
            $credential = OrderCredential::query()->where('order_id', $this->realOrder($order)->id)->lockForUpdate()->first();
            abort_if($credential === null, 404, 'This order has no customer login.');
            abort_unless($credential->hasSecretField($field), 422, 'Not a secret field of this login.');
            abort_if(Order::query()->closed()->whereKey($order->id)->exists(), 409, 'This order is closed; its login is no longer needed.');

            $secrets = $credential->purged_at === null ? ($credential->secrets ?? []) : [];
            $now = now();
            $credential->forceFill([
                'secrets' => [...$secrets, $field => $request->validated('value')],
                'secrets_set_at' => $now,
                'secrets_expire_at' => OrderCredential::expiryFor($this->realOrder($order), $now),
                'purged_at' => null,
            ])->save();

            $this->audit($request, $order, CredentialReveal::ACTION_REPLACE, $field);

            return $credential->adminSummary();
        });

        return response()->json($summary);
    }

    private function credentialFor(Order $order): OrderCredential
    {
        $credential = OrderCredential::query()->where('order_id', $this->realOrder($order)->id)->first();
        abort_if($credential === null, 404, 'This order has no customer login.');

        return $credential;
    }

    private function realOrder(Order $order): Order
    {
        abort_if($order->is_test, 404);

        return $order;
    }

    private function audit(Request $request, Order $order, string $action, string $field): void
    {
        CredentialReveal::query()->create([
            'order_id' => $order->id,
            'actor_type' => CredentialReveal::ACTOR_ADMIN,
            'actor_id' => $request->user()->id,
            'action' => $action,
            'field_key' => $field,
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
