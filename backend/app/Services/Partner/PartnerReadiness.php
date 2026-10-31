<?php

namespace App\Services\Partner;

/** The checklist of one affiliate or reseller, and whether they are ready to sell (affiliate) or to order (reseller). */
final readonly class PartnerReadiness
{
    /** @param  list<ReadinessStep>  $steps */
    public function __construct(
        public array $steps,
        public bool $ready,
    ) {}

    public function step(string $key): ReadinessStep
    {
        foreach ($this->steps as $step) {
            if ($step->key === $key) {
                return $step;
            }
        }

        throw new \InvalidArgumentException("No readiness step [{$key}].");
    }

    /** The portal's shape: the whole checklist. */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'steps' => array_map(fn (ReadinessStep $s) => [
                'key' => $s->key,
                'state' => $s->state->value,
                'required' => $s->required,
                'detail' => $s->detail,
            ], $this->steps),
        ];
    }

    /** The admin list's shape: ready, what is still open, and whether only PekanGame can move it on. */
    public function toSummary(): array
    {
        return [
            'ready' => $this->ready,
            'needs' => $this->needs(),
            'waiting_on_us' => collect($this->steps)->contains(fn (ReadinessStep $s) => $s->state === StepState::WaitingOnUs),
        ];
    }

    /** @return list<string> the required steps still open, for the admin list's "Needs: ..." */
    public function needs(): array
    {
        return array_values(array_map(
            fn (ReadinessStep $s) => $s->key,
            array_filter($this->steps, fn (ReadinessStep $s) => $s->required && $s->state !== StepState::Done),
        ));
    }
}
