<?php

namespace App\Services\Partner;

/**
 * One checklist line. `required` means the partner cannot be "ready" without it; `detail` carries
 * the one fact a screen may need (today only the DNS step's teardown deadline, ISO 8601).
 */
final readonly class ReadinessStep
{
    public function __construct(
        public string $key,
        public StepState $state,
        public bool $required = false,
        public ?string $detail = null,
    ) {}
}
