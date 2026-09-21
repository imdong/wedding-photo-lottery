<?php

namespace Tests\Unit;

use App\Exceptions\NoWeightedCandidatesException;
use App\WeightedRandomSelector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SequenceLotteryRandomSource;
use Tests\TestCase;
use UnexpectedValueException;

class WeightedRandomSelectorTest extends TestCase
{
    #[DataProvider('selectionCases')]
    public function test_selects_by_cumulative_weight_and_skips_zero_weight(
        float $randomRatio,
        string $expectedId,
    ): void {
        $selector = new WeightedRandomSelector(
            new SequenceLotteryRandomSource([$randomRatio]),
        );
        $items = [
            ['id' => 'zero', 'weight' => 0.0],
            ['id' => 'first', 'weight' => 1.0],
            ['id' => 'second', 'weight' => 3.0],
        ];

        $selected = $selector->select(
            $items,
            static fn (array $item): float => $item['weight'],
        );

        $this->assertSame($expectedId, $selected['id']);
    }

    public function test_rejects_a_pool_without_positive_weight(): void
    {
        $selector = new WeightedRandomSelector(new SequenceLotteryRandomSource([0.0]));

        $this->expectException(NoWeightedCandidatesException::class);

        $selector->select(
            [['id' => 'zero', 'weight' => 0.0]],
            static fn (array $item): float => $item['weight'],
        );
    }

    #[DataProvider('invalidWeights')]
    public function test_rejects_invalid_weights(float $weight): void
    {
        $selector = new WeightedRandomSelector(new SequenceLotteryRandomSource([0.0]));

        $this->expectException(UnexpectedValueException::class);

        $selector->select(
            [['id' => 'invalid', 'weight' => $weight]],
            static fn (array $item): float => $item['weight'],
        );
    }

    public function test_rejects_a_random_value_outside_the_requested_interval(): void
    {
        $selector = new WeightedRandomSelector(new SequenceLotteryRandomSource([1.0]));

        $this->expectException(UnexpectedValueException::class);

        $selector->select(
            [['id' => 'candidate', 'weight' => 1.0]],
            static fn (array $item): float => $item['weight'],
        );
    }

    /** @return array<string, array{float, string}> */
    public static function selectionCases(): array
    {
        return [
            'start selects first positive item' => [0.0, 'first'],
            'exact boundary selects next item' => [0.25, 'second'],
            'end of interval selects last item' => [0.999999, 'second'],
        ];
    }

    /** @return array<string, array{float}> */
    public static function invalidWeights(): array
    {
        return [
            'negative' => [-1.0],
            'positive infinity' => [INF],
            'not a number' => [NAN],
        ];
    }
}
