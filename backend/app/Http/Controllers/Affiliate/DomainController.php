<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Affiliate\Concerns\AssertsAffiliateWritable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Affiliate\StoreAffiliateDomainRequest;
use App\Models\AffiliateDomain;
use App\Services\Affiliate\Domain\AffiliateDomainService;
use App\Services\Affiliate\Domain\DomainProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum, section C/D): the
 * affiliate-portal Domain screen. Full self-serve — no admin approval
 * step (provider-side DNS control IS the ownership proof).
 *
 * Provider opacity (section C): every string this controller returns is
 * provider-agnostic. The affiliate is told to point a CNAME at
 * `connect.pekangame.space` (a platform-owned alias); Vercel is never
 * named, and `AffiliateDomainService` has already sanitised any provider
 * error into `last_error` / the thrown `DomainProviderException` message.
 *
 * A deactivated affiliate's screen is read-only — enforced by
 * `assertWritable()` (the auth guard already blocks a non-active
 * affiliate's portal users from most of the portal, this is the backstop
 * for the write actions).
 */
class DomainController extends Controller
{
    use AssertsAffiliateWritable;

    public function __construct(private readonly AffiliateDomainService $domains) {}

    public function index(Request $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();

        $domains = $affiliate->customDomains()
            ->orderByDesc('is_primary')
            ->orderBy('hostname')
            ->get();
        $targets = $domains->pluck('hostname', 'id');

        return response()->json([
            'domains' => $domains->map(fn (AffiliateDomain $d) => $this->shape($d, $targets[$d->redirects_to_domain_id] ?? null))->values(),
            'max_domains' => AffiliateDomainService::MAX_DOMAINS,
            // The cap counts domains the affiliate chose, not the www redirects we add for them.
            'domains_used' => $domains->whereNull('redirects_to_domain_id')->count(),
            'writable' => $affiliate->status === 'active',
            'dns' => [
                // The recommended path — a CNAME on `www.` / `shop.`
                // keeps the provider fully hidden.
                'cname_target' => config('services.vercel.connect_cname'),
                // Only needed for an apex domain on a DNS host with no
                // ALIAS/flattening support.
                'apex_a_record' => config('services.vercel.apex_a_record'),
            ],
        ]);
    }

    public function store(StoreAffiliateDomainRequest $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $this->assertWritable($affiliate);

        try {
            $domain = $this->domains->add($affiliate, $request->validated('hostname'));
        } catch (DomainProviderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['hostname' => [$e->getMessage()]],
            ], 422);
        }

        // ADR-060 second 2026-10-30 addendum decision 5: the main domain stands even if its www twin fails.
        $www = null;
        $wwwError = null;
        if ($request->boolean('with_www')) {
            try {
                $www = $this->shape($this->domains->pairWww($domain), $domain->hostname);
            } catch (DomainProviderException $e) {
                $wwwError = $e->getMessage();
            } catch (ValidationException $e) {
                $wwwError = collect($e->errors())->flatten()->first();
            }
        }

        return response()->json([...$this->shape($domain), 'www' => $www, 'www_error' => $wwwError], 201);
    }

    /** Add (or re-add, or convert an existing) `www` redirect for one of the affiliate's domains. */
    public function addWww(Request $request, AffiliateDomain $domain): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        abort_unless($domain->affiliate_id === $affiliate->id, 404);
        $this->assertWritable($affiliate);

        try {
            $twin = $this->domains->pairWww($domain);
        } catch (DomainProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->shape($twin, $domain->hostname));
    }

    public function recheck(Request $request, AffiliateDomain $domain): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        abort_unless($domain->affiliate_id === $affiliate->id, 404);

        try {
            $domain = $this->domains->recheck($domain);
        } catch (DomainProviderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json($this->shape($domain));
    }

    public function setPrimary(Request $request, AffiliateDomain $domain): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        abort_unless($domain->affiliate_id === $affiliate->id, 404);
        $this->assertWritable($affiliate);

        $this->domains->setPrimary($domain);

        return response()->json($this->shape($domain->refresh()));
    }

    public function destroy(Request $request, AffiliateDomain $domain): Response
    {
        $affiliate = $request->user()->affiliateOwner();
        abort_unless($domain->affiliate_id === $affiliate->id, 404);
        $this->assertWritable($affiliate);

        try {
            $this->domains->remove($domain);
        } catch (DomainProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(AffiliateDomain $domain, ?string $redirectsTo = null): array
    {
        return [
            'id' => $domain->id,
            'hostname' => $domain->hostname,
            'status' => $domain->status->value,
            'is_primary' => $domain->is_primary,
            // ADR-060 second 2026-10-30 addendum: the domain this `www` address redirects to, else null.
            'redirects_to' => $domain->isRedirectTwin() ? ($redirectsTo ?? $domain->redirectTarget?->hostname) : null,
            // ADR-131: the domain serves the store at once, but takes payments once PekanGame approves it.
            'payments_approved' => $domain->paymentsApproved(),
            // The extra ownership-TXT records, only present in the rare
            // "already registered elsewhere" case. Already provider-
            // neutral in shape (type/name/value).
            'verification' => $domain->verification ?? [],
            'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
            'verified_at' => $domain->verified_at?->toIso8601String(),
            'last_error' => $domain->last_error,
        ];
    }
}
