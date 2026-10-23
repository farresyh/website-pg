<?php

namespace App\Services\Joki;

/**
 * ADR-126 decisions 3 and 8: one joki price, in whole ringgit, with its
 * lines and the snapshot of the model, rates and grading rule it came
 * from (stored on `joki_orders.quote`). `cheaperRoute` is the route
 * package selling the same climb for less, if any (decision 5).
 */
final readonly class JokiQuote
{
    /**
     * @param  list<array{label: string, stars: ?int, rate_rm: ?int, amount_rm: int}>  $lines
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @param  array{package_id: int, name: string, price_rm: int}|null  $cheaperRoute
     * @param  array<string, mixed>  $model
     */
    public function __construct(
        public int $totalRm,
        public array $lines,
        public array $from,
        public array $to,
        public bool $isExpress,
        public ?int $routePackageId,
        public ?array $cheaperRoute,
        private array $model,
    ) {}

    public function sellingSen(): int
    {
        return $this->totalRm * 100;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'is_express' => $this->isExpress,
            'route_package_id' => $this->routePackageId,
            'lines' => $this->lines,
            'total_rm' => $this->totalRm,
            ...$this->model,
        ];
    }

    /** @return array<string, mixed> What the `/joki` page shows. */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'is_express' => $this->isExpress,
            'lines' => $this->lines,
            'total_rm' => $this->totalRm,
            'cheaper_route' => $this->cheaperRoute,
        ];
    }
}
