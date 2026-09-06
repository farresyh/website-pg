<?php

namespace Tests\Support;

use App\Services\Affiliate\Domain\AffiliateDomainProvider;
use App\Services\Affiliate\Domain\DomainProviderException;
use App\Services\Affiliate\Domain\DomainProviderState;

/**
 * In-memory `AffiliateDomainProvider` for the ADR-060 PR-5 tests — no
 * network, fully scriptable. Bind it in a test with
 * `$this->app->instance(AffiliateDomainProvider::class, $fake)`.
 */
class FakeAffiliateDomainProvider implements AffiliateDomainProvider
{
    /** @var array<int, array{op: string, hostname: string}> */
    public array $calls = [];

    /** hostname => the state attach() / refresh() should return next. */
    private array $states = [];

    /** hostname => a DomainProviderException to throw on the next attach/refresh/detach. */
    private array $failures = [];

    public bool $failNextAttach = false;

    public function attach(string $hostname): DomainProviderState
    {
        $this->calls[] = ['op' => 'attach', 'hostname' => $hostname];

        if ($this->failNextAttach) {
            $this->failNextAttach = false;
            throw DomainProviderException::alreadyInUse();
        }

        $this->maybeThrow($hostname);

        return $this->states[$hostname] ??= new DomainProviderState(providerRef: $hostname, verified: false);
    }

    public function refresh(string $hostname): DomainProviderState
    {
        $this->calls[] = ['op' => 'refresh', 'hostname' => $hostname];
        $this->maybeThrow($hostname);

        return $this->states[$hostname] ?? new DomainProviderState(providerRef: $hostname, verified: false);
    }

    public function detach(string $hostname): void
    {
        $this->calls[] = ['op' => 'detach', 'hostname' => $hostname];
        $this->maybeThrow($hostname);
    }

    public function willReturn(string $hostname, DomainProviderState $state): self
    {
        $this->states[$hostname] = $state;

        return $this;
    }

    public function markVerified(string $hostname): self
    {
        return $this->willReturn($hostname, new DomainProviderState(providerRef: $hostname, verified: true));
    }

    public function markMissing(string $hostname): self
    {
        return $this->willReturn($hostname, new DomainProviderState(providerRef: $hostname, verified: false, missing: true));
    }

    public function willFail(string $hostname, ?DomainProviderException $e = null): self
    {
        $this->failures[$hostname] = $e ?? DomainProviderException::generic();

        return $this;
    }

    public function opCount(string $op): int
    {
        return count(array_filter($this->calls, fn ($c) => $c['op'] === $op));
    }

    private function maybeThrow(string $hostname): void
    {
        if (isset($this->failures[$hostname])) {
            $e = $this->failures[$hostname];
            unset($this->failures[$hostname]);
            throw $e;
        }
    }
}
