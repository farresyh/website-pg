<?php

namespace App\Services\Partner;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateDomain;
use App\Models\AffiliateUser;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerApiKey;
use App\Models\ResellerWebhook;
use App\Models\WhatsAppGroup;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ADR-133 decisions 13-15: what a partner still has to do, computed on read from the facts the
 * real gates already use. Read-only and stateless: it never decides anything itself, so it cannot
 * drift from the code that does. Every fact is loaded for the whole batch at once (a fixed number
 * of queries however many partners), and a single partner is just a batch of one.
 */
final class PartnerReadinessService
{
    /** The platform's default theme: an unbranded store looks like PekanGame's own. */
    private const DEFAULT_THEME = 'default';

    public function forAffiliate(Affiliate $affiliate): PartnerReadiness
    {
        return $this->forAffiliates([$affiliate])[$affiliate->id];
    }

    public function forReseller(Reseller $reseller): PartnerReadiness
    {
        return $this->forResellers([$reseller])[$reseller->id];
    }

    /**
     * @param  iterable<Affiliate>  $affiliates
     * @return array<int, PartnerReadiness> keyed by affiliate id
     */
    public function forAffiliates(iterable $affiliates): array
    {
        $affiliates = Collection::make($affiliates)->keyBy('id');
        if ($affiliates->isEmpty()) {
            return [];
        }
        $ids = $affiliates->keys()->all();

        $withPassword = $this->idsWithPassword(AccountOwnerType::Affiliate, $ids);
        $domains = AffiliateDomain::query()->servable()->whereIn('affiliate_id', $ids)->get()->groupBy('affiliate_id');
        $branding = AffiliateBranding::query()->whereIn('affiliate_id', $ids)->get()->keyBy('affiliate_id');
        $withOrder = Order::query()->whereIn('affiliate_id', $ids)->whereNull('wallet_reseller_id')->paidAndReal()
            ->distinct()->pluck('affiliate_id')->flip();

        return $affiliates->map(function (Affiliate $a) use ($withPassword, $domains, $branding, $withOrder) {
            /** @var Collection<int, AffiliateDomain> $mine */
            $mine = $domains->get($a->id, new Collection);
            $active = $mine->filter(fn (AffiliateDomain $d) => $d->status === AffiliateDomainStatus::Active);
            $approved = $active->contains(fn (AffiliateDomain $d) => $d->paymentsApproved());
            $theme = $branding->get($a->id);

            $steps = [
                new ReadinessStep('password', $this->done($withPassword->has($a->id))),
            ];
            if (! $a->is_owned) {
                $steps[] = new ReadinessStep('bank', $this->done($a->hasBankDetails()));
            }
            $steps[] = new ReadinessStep('domain', $this->done($mine->isNotEmpty()), required: true);
            $steps[] = new ReadinessStep('dns', $this->done($active->isNotEmpty()), required: true, detail: $this->dnsDeadline($mine));
            $steps[] = new ReadinessStep('payments_approval', match (true) {
                $approved => StepState::Done,
                $active->isNotEmpty() => StepState::WaitingOnUs,
                default => StepState::Todo,
            }, required: true);
            $steps[] = new ReadinessStep('branding', $this->done(
                $theme !== null && $theme->logo_path !== null && $theme->theme_preset !== null && $theme->theme_preset !== self::DEFAULT_THEME,
            ));
            if (! $a->is_owned) {
                $steps[] = new ReadinessStep('markup', StepState::Optional);
            }
            $steps[] = new ReadinessStep('first_order', $this->done($withOrder->has($a->id)));

            return $this->build($steps, $a->status === 'active');
        })->all();
    }

    /**
     * @param  iterable<Reseller>  $resellers
     * @return array<int, PartnerReadiness> keyed by reseller id
     */
    public function forResellers(iterable $resellers): array
    {
        $resellers = Collection::make($resellers)->keyBy('id');
        if ($resellers->isEmpty()) {
            return [];
        }
        $ids = $resellers->keys()->all();

        $withPassword = $this->idsWithPassword(AccountOwnerType::Reseller, $ids);
        $funded = LedgerEntry::query()->where('owner_type', LedgerOwnerType::ResellerWallet->value)->whereIn('owner_id', $ids)
            ->where('amount', '>', 0)->distinct()->pluck('owner_id')->flip();
        $withKey = ResellerApiKey::query()->whereIn('reseller_id', $ids)->whereNull('revoked_at')->distinct()->pluck('reseller_id')->flip();
        $withGroup = WhatsAppGroup::query()->where('owner_type', WhatsAppGroup::OWNER_RESELLER)->whereIn('owner_id', $ids)
            ->where('is_active', true)->distinct()->pluck('owner_id')->flip();
        $withOrder = Order::query()->whereIn('wallet_reseller_id', $ids)->paidAndReal()->distinct()->pluck('wallet_reseller_id')->flip();
        $withWebhook = ResellerWebhook::query()->whereIn('reseller_id', $ids)->distinct()->pluck('reseller_id')->flip();

        return $resellers->map(function (Reseller $r) use ($withPassword, $funded, $withKey, $withGroup, $withOrder, $withWebhook) {
            $steps = [
                new ReadinessStep('password', $this->done($withPassword->has($r->id))),
                new ReadinessStep('tier', $this->done($r->reseller_tier_id !== null), required: true),
                new ReadinessStep('add_balance', $this->done($funded->has($r->id)), required: true),
                new ReadinessStep('order_channel', $this->done($withKey->has($r->id) || $withGroup->has($r->id)), required: true),
                new ReadinessStep('first_order', $this->done($withOrder->has($r->id))),
                new ReadinessStep('webhook', $withWebhook->has($r->id) ? StepState::Done : StepState::Optional),
            ];

            return $this->build($steps, (bool) $r->is_active);
        })->all();
    }

    /** @param  list<ReadinessStep>  $steps */
    private function build(array $steps, bool $accountActive): PartnerReadiness
    {
        $ready = $accountActive && collect($steps)->every(fn (ReadinessStep $s) => ! $s->required || $s->state === StepState::Done);

        return new PartnerReadiness($steps, $ready);
    }

    private function done(bool $isDone): StepState
    {
        return $isDone ? StepState::Done : StepState::Todo;
    }

    /**
     * Owner ids that have at least one portal user who has set a password.
     *
     * @param  list<int>  $ownerIds
     * @return Collection<int, int> keyed by owner id
     */
    private function idsWithPassword(AccountOwnerType $type, array $ownerIds): Collection
    {
        return AffiliateUser::query()->where('owner_type', $type->value)->whereIn('owner_id', $ownerIds)
            ->whereNotNull('password')->distinct()->pluck('owner_id')->flip();
    }

    /**
     * The day the daily sweep tears down the earliest domain still waiting on DNS
     * (`app:sync-affiliate-domain-status`: pending, with a provider reference, older than the TTL).
     *
     * @param  Collection<int, AffiliateDomain>  $domains
     */
    private function dnsDeadline(Collection $domains): ?string
    {
        $ttl = (int) config('services.vercel.stuck_pending_days', 14);

        $oldest = $domains
            ->filter(fn (AffiliateDomain $d) => $d->status === AffiliateDomainStatus::Pending && $d->provider_ref !== null)
            ->min('created_at');

        return $oldest === null ? null : CarbonImmutable::parse($oldest)->addDays($ttl)->toIso8601String();
    }
}
