<?php

namespace App\Console\Commands\Testing;

use App\Models\OrderDeliveryLeg;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Currency\CurrencyRateService;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Ledger\LedgerService;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Order\OrderStatusService;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOutcome;
use App\Services\Voucher\VoucherService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * ADR-103 decision 2/consequence-to-track: the leg-level counterpart to
 * OrderFinalizePendingDeliveryTestFinalize — invoked as a genuinely
 * separate OS process so two finalizePendingDeliveryLeg() calls for the
 * same OrderDeliveryLeg (a duplicate webhook delivery for one combo leg)
 * race for real, proving the leg's own lockForUpdate() serializes the
 * NEW `resend_unsafe_with_same_reference` write exactly like it already
 * serializes the leg's `status` write.
 */
#[Signature('app:order-delivery-leg-finalize-test-finalize {legId} {resultFile}')]
#[Description('Test-only: attempt to finalize a single combo OrderDeliveryLeg as needs_review and write the outcome to a result file.')]
class OrderDeliveryLegFinalizeTestFinalize extends Command
{
    public function handle(): int
    {
        $leg = OrderDeliveryLeg::query()->findOrFail((int) $this->argument('legId'));
        $resultFile = $this->argument('resultFile');

        $service = new OrderFulfillmentService(
            new OrderStatusService,
            new ReferenceNumberService,
            app(SupplierAdapterFactory::class),
            new LedgerService,
            app(VoucherService::class),
            app(SupplierFundingService::class),
            app(CurrencyRateService::class),
        );

        try {
            $service->finalizePendingDeliveryLeg(
                $leg,
                SupplierOutcome::Failure,
                null,
                ['error_message' => 'duplicate_reference'],
                resendUnsafeWithSameReference: true,
                outcomeConfirmedFailed: false,
            );
            file_put_contents($resultFile, 'success');
        } catch (InvalidOrderTransitionException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
