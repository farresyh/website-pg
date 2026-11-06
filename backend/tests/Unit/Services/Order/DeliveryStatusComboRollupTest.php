<?php

namespace Tests\Unit\Services\Order;

use App\Services\Order\DeliveryStatus as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR-103 decision 14: the one rule that rolls a combo's legs up to its order. */
class DeliveryStatusComboRollupTest extends TestCase
{
    /** @return array<string, array{0: list<S>, 1: S}> */
    public static function table(): array
    {
        return [
            'no legs' => [[], S::NeedsReview],
            'all delivered' => [[S::Delivered, S::Delivered], S::Delivered],
            'all failed' => [[S::Failed, S::Failed], S::Failed],
            'delivered and failed' => [[S::Delivered, S::Failed], S::PartiallyDelivered],
            'one still at the supplier' => [[S::Delivered, S::Pending], S::Pending],
            'pending beats review' => [[S::Pending, S::NeedsReview], S::Pending],
            'pending beats never tried' => [[S::Pending, S::NotStarted], S::Pending],
            'ambiguous leg' => [[S::Delivered, S::NeedsReview], S::NeedsReview],
            'never tried is not partial' => [[S::Delivered, S::NotStarted], S::NeedsReview],
            'in flight is not partial' => [[S::Delivered, S::Processing], S::NeedsReview],
            'order-only value on a leg' => [[S::Delivered, S::PartiallyDelivered], S::NeedsReview],
        ];
    }

    #[DataProvider('table')]
    public function test_the_rollup_table(array $legs, S $expected): void
    {
        $this->assertSame($expected, S::comboRollup($legs));
    }

    /** Every mix of up to three legs: a known outcome only ever comes from legs that prove it. */
    public function test_no_mix_reads_as_an_outcome_it_does_not_prove(): void
    {
        $all = S::cases();

        foreach ($all as $a) {
            foreach ($all as $b) {
                foreach ($all as $c) {
                    $legs = [$a, $b, $c];
                    $result = S::comboRollup($legs);
                    $only = fn (S ...$allowed) => array_diff(array_map(fn (S $s) => $s->value, $legs), array_map(fn (S $s) => $s->value, $allowed)) === [];

                    if ($result === S::Delivered) {
                        $this->assertTrue($only(S::Delivered));
                    }

                    if ($result === S::Failed) {
                        $this->assertTrue($only(S::Failed));
                    }

                    if ($result === S::PartiallyDelivered) {
                        $this->assertTrue($only(S::Delivered, S::Failed));
                    }

                    $this->assertContains($result, [S::Delivered, S::Failed, S::PartiallyDelivered, S::Pending, S::NeedsReview]);
                }
            }
        }
    }
}
