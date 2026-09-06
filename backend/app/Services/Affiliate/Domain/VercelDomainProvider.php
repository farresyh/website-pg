<?php

namespace App\Services\Affiliate\Domain;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum, section B/C): attaches
 * an affiliate's custom domain to the ONE `storefront/` Vercel project.
 * Vercel issues and auto-renews the per-domain certificate.
 *
 * Endpoints (Vercel REST API, versioned path segments per their current
 * docs):
 *  - add     `POST   /v10/projects/{project}/domains`          body {name}
 *  - get     `GET    /v9/projects/{project}/domains/{domain}`
 *  - verify  `POST   /v9/projects/{project}/domains/{domain}/verify`
 *  - remove  `DELETE /v9/projects/{project}/domains/{domain}`
 * all with `?teamId=` since the project lives under a team.
 *
 * Provider opacity (addendum section C): every failure is re-thrown as a
 * `DomainProviderException` whose message is affiliate-safe and never
 * names Vercel; the raw provider error goes to the log only. A hard
 * connection failure (DNS, timeout) maps to the same generic exception.
 */
final class VercelDomainProvider implements AffiliateDomainProvider
{
    public function __construct(
        private readonly string $token,
        private readonly string $teamId,
        private readonly string $projectId,
        private readonly string $baseUrl = 'https://api.vercel.com',
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 5,
    ) {}

    public function attach(string $hostname): DomainProviderState
    {
        $response = $this->send(
            fn () => $this->request()->post(
                "/v10/projects/{$this->projectId}/domains",
                ['name' => $hostname],
            ),
            'attach',
            $hostname,
        );

        if ($response->failed()) {
            throw $this->translate($response, 'attach', $hostname);
        }

        return $this->stateFromPayload($hostname, $response->json());
    }

    public function refresh(string $hostname): DomainProviderState
    {
        $path = "/v9/projects/{$this->projectId}/domains/".rawurlencode($hostname);

        $get = $this->send(fn () => $this->request()->get($path), 'refresh', $hostname);

        if ($get->status() === 404) {
            return new DomainProviderState(providerRef: $hostname, verified: false, missing: true);
        }

        if ($get->failed()) {
            throw $this->translate($get, 'refresh', $hostname);
        }

        if ((bool) $get->json('verified') === true) {
            return $this->stateFromPayload($hostname, $get->json());
        }

        // The affiliate just set their DNS and clicked "Check now" — ask
        // Vercel to re-run the challenge check immediately.
        $verify = $this->send(fn () => $this->request()->post($path.'/verify'), 'verify', $hostname);

        if ($verify->status() === 404) {
            return new DomainProviderState(providerRef: $hostname, verified: false, missing: true);
        }

        if ($verify->failed()) {
            // "Not there yet" is a normal still-pending, not a hard error.
            if ($this->errorCode($verify) === 'domain_verification_failed') {
                return $this->stateFromPayload($hostname, $get->json());
            }

            throw $this->translate($verify, 'verify', $hostname);
        }

        return $this->stateFromPayload($hostname, [
            'name' => $hostname,
            'verified' => (bool) $verify->json('verified'),
            'verification' => $get->json('verification'),
        ]);
    }

    public function detach(string $hostname): void
    {
        $response = $this->send(
            fn () => $this->request()->delete(
                "/v9/projects/{$this->projectId}/domains/".rawurlencode($hostname),
            ),
            'detach',
            $hostname,
        );

        // 404 = already gone. Idempotent by contract.
        if ($response->successful() || $response->status() === 404) {
            return;
        }

        throw $this->translate($response, 'detach', $hostname);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->withQueryParameters(['teamId' => $this->teamId])
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds);
    }

    /**
     * @param  callable(): Response  $call
     */
    private function send(callable $call, string $op, string $hostname): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            Log::warning('Vercel domain API unreachable', [
                'op' => $op,
                'hostname' => $hostname,
                'error' => $e->getMessage(),
            ]);

            throw DomainProviderException::generic($e);
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function stateFromPayload(string $hostname, ?array $payload): DomainProviderState
    {
        $verification = $payload['verification'] ?? null;

        return new DomainProviderState(
            providerRef: is_string($payload['name'] ?? null) ? $payload['name'] : $hostname,
            verified: (bool) ($payload['verified'] ?? false),
            verification: is_array($verification) && $verification !== [] ? $verification : null,
        );
    }

    private function errorCode(Response $response): ?string
    {
        $code = $response->json('error.code');

        return is_string($code) ? $code : null;
    }

    private function translate(Response $response, string $op, string $hostname): DomainProviderException
    {
        $code = $this->errorCode($response);

        Log::warning('Vercel domain API call failed', [
            'op' => $op,
            'hostname' => $hostname,
            'status' => $response->status(),
            'code' => $code,
            'body' => $response->json('error') ?? $response->body(),
        ]);

        $alreadyInUse = in_array($code, ['domain_already_in_use', 'domain_taken', 'domain_already_exists'], true)
            || $response->status() === 409;

        return $alreadyInUse
            ? DomainProviderException::alreadyInUse()
            : DomainProviderException::generic();
    }
}
