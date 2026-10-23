<?php

namespace App\Console\Commands\Testing;

use App\Models\AdminUser;
use App\Models\Supplier;
use App\Services\Supplier\Manual\ManualSupplierStatusService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/** Test-only: switch a manual supplier off in its own OS process, to race an Assign (ADR-126 decision 19). */
#[Signature('app:supplier-test-switch-off {supplierId} {adminUserId} {resultFile}')]
#[Description('Test-only: attempt to switch a manual supplier off and write the outcome to a result file.')]
class SupplierTestSwitchOff extends Command
{
    public function handle(ManualSupplierStatusService $status): int
    {
        $supplier = Supplier::query()->findOrFail((int) $this->argument('supplierId'));
        $resultFile = $this->argument('resultFile');
        AdminUser::query()->findOrFail((int) $this->argument('adminUserId'));

        try {
            $status->setActive($supplier, false, (int) $this->argument('adminUserId'));
            file_put_contents($resultFile, 'success');
        } catch (ValidationException $e) {
            file_put_contents($resultFile, 'failed:'.json_encode($e->errors()));
        }

        return self::SUCCESS;
    }
}
