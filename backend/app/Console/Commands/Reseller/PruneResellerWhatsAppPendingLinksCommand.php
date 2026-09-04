<?php

namespace App\Console\Commands\Reseller;

use App\Services\Reseller\Bot\ResellerWhatsAppGroupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * PR-F build addendum decision 3 — an unmatched `reseller_whatsapp_
 * pending_links` row past its 24-hour TTL (`services.openwa.
 * pending_link_ttl_hours`) is deleted rather than accumulating stale
 * noise on the admin linking screen. Same inert-until-real-cron pattern
 * as PrunePlayerValidationsCommand/PruneSupplierRequestLogsCommand.
 */
#[Signature('app:prune-reseller-whatsapp-pending-links')]
#[Description('Delete reseller_whatsapp_pending_links rows past their TTL, still unlinked.')]
class PruneResellerWhatsAppPendingLinksCommand extends Command
{
    public function handle(ResellerWhatsAppGroupService $groups): int
    {
        $ttlHours = (int) config('services.openwa.pending_link_ttl_hours');
        $deleted = $groups->pruneExpiredPendingLinks($ttlHours);

        $this->info("Pruned {$deleted} reseller_whatsapp_pending_links row(s) older than {$ttlHours} hour(s).");
        Log::info('Pruned reseller_whatsapp_pending_links rows', ['deleted' => $deleted, 'ttl_hours' => $ttlHours]);

        return self::SUCCESS;
    }
}
