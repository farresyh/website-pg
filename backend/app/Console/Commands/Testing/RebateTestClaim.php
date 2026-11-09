<?php

namespace App\Console\Commands\Testing;

use App\Models\AffiliateUser;
use App\Models\Reseller;
use App\Services\Rebate\RebateClaimService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

#[Signature('app:rebate-test-claim {resellerId} {userId} {year} {month} {step} {resultFile}')]
#[Description('Test-only: attempt one rebate claim and write the outcome to a result file (ADR-139 concurrency proof).')]
class RebateTestClaim extends Command
{
    public function handle(RebateClaimService $claims): int
    {
        try {
            $claims->claim(
                Reseller::query()->findOrFail((int) $this->argument('resellerId')),
                AffiliateUser::query()->findOrFail((int) $this->argument('userId')),
                (int) $this->argument('year'),
                (int) $this->argument('month'),
                (int) $this->argument('step'),
                acceptTerms: true,
            );
            file_put_contents($this->argument('resultFile'), 'success');
        } catch (ValidationException|UniqueConstraintViolationException $e) {
            file_put_contents($this->argument('resultFile'), 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
