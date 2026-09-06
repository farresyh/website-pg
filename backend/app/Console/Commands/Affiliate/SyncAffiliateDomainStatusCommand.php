<?php

namespace App\Console\Commands\Affiliate;

use App\Models\AffiliateDomain;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Affiliate\Domain\AffiliateDomainService;
use App\Services\Affiliate\Domain\DomainProviderException;
use App\Services\Membership\PlunkMailer;
use App\Services\Membership\PlunkSendException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum, section G/H): the
 * daily backstop for custom-domain status. Same inert-until-a-real-OS-cron
 * pattern as the other reconcile commands (routes/console.php).
 *
 * For every provider-managed row that is not yet `active` and not
 * `suspended`:
 *  - a row stuck `pending` past `services.vercel.stuck_pending_days`
 *    (default 14) is torn down at the provider and marked `failed`;
 *  - otherwise the provider is polled and the row re-mapped
 *    (`pending` → `active`, or `active` → `failed` if the DNS was pulled).
 *
 * Still-`pending` rows get a Plunk nudge on day 3 and day 7 (best-effort;
 * a skipped cron day just skips that day's reminder).
 *
 * Null-`provider_ref` rows — the primary affiliate's seeded hostnames —
 * are never selected here.
 */
#[Signature('app:sync-affiliate-domain-status')]
#[Description('Poll the hosting provider for every pending/failed affiliate custom domain; tear down domains stuck pending past the TTL; email day-3 / day-7 reminders.')]
class SyncAffiliateDomainStatusCommand extends Command
{
    public function handle(AffiliateDomainService $service, PlunkMailer $mailer): int
    {
        $ttlDays = (int) config('services.vercel.stuck_pending_days', 14);

        $rows = AffiliateDomain::query()
            ->whereNotNull('provider_ref')
            ->whereIn('status', [AffiliateDomainStatus::Pending->value, AffiliateDomainStatus::Failed->value])
            ->with('affiliate')
            ->get();

        $activated = 0;
        $tornDown = 0;
        $failed = 0;
        $reminded = 0;

        foreach ($rows as $domain) {
            // diffInDays is signed in this Carbon version — abs() so age
            // is always a positive whole-day count.
            $ageDays = (int) abs($domain->created_at->diffInDays(now()));

            if ($domain->status === AffiliateDomainStatus::Pending && $ageDays >= $ttlDays) {
                try {
                    $service->tearDownStuckPending($domain);
                    $tornDown++;
                } catch (DomainProviderException $e) {
                    Log::warning('Stuck-pending domain teardown failed', [
                        'domain_id' => $domain->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                continue;
            }

            try {
                $before = $domain->status;
                $after = $service->recheck($domain)->status;

                if ($before !== AffiliateDomainStatus::Active && $after === AffiliateDomainStatus::Active) {
                    $activated++;
                } elseif ($before !== AffiliateDomainStatus::Failed && $after === AffiliateDomainStatus::Failed) {
                    $failed++;
                }

                if ($after === AffiliateDomainStatus::Pending && in_array($ageDays, [3, 7], true)) {
                    $reminded += $this->remind($mailer, $domain) ? 1 : 0;
                }
            } catch (DomainProviderException $e) {
                Log::warning('Affiliate domain re-check failed', [
                    'domain_id' => $domain->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Affiliate domains: {$activated} activated, {$failed} failed, {$tornDown} torn down (stuck), {$reminded} reminders sent.");
        Log::info('Synced affiliate domain status', compact('activated', 'failed', 'tornDown', 'reminded'));

        return self::SUCCESS;
    }

    private function remind(PlunkMailer $mailer, AffiliateDomain $domain): bool
    {
        $email = $domain->affiliate?->email;

        if ($email === null) {
            return false;
        }

        try {
            $mailer->send(
                $email,
                'Action needed: finish setting up '.$domain->hostname,
                "Your storefront domain {$domain->hostname} is still waiting for its DNS records.\n\n".
                'Sign in to your portal, open Domains, and follow the CNAME instructions shown there. '.
                'If the records are not in place within a couple of weeks the domain is removed automatically and you can add it again later.',
            );

            return true;
        } catch (PlunkSendException $e) {
            Log::warning('Domain reminder email failed', ['domain_id' => $domain->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
