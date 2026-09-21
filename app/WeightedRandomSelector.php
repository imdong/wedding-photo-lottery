<?php

namespace App;

use App\Exceptions\NoWeightedCandidatesException;
use UnexpectedValueException;

final readonly class WeightedRandomSelector
{
    public function __construct(
        private LotteryRandomSource $randomSource,
    ) {}

    /**
     * @template TValue
     *
     * @param  iterable<TValue>  $items
     * @param  callable(TValue): float  $weightFor
     * @return TValue
     */
    public function select(iterable $items, callable $weightFor): mixed
    {
        $eligibleItems = [];
        $totalWeight = 0.0;

        foreach ($items as $item) {
            $weight = $weightFor($item);

            if (! is_finite($weight) || $weight < 0.0) {
                throw new UnexpectedValueException('候选权重必须是有限的非负数。');
            }

            if ($weight === 0.0) {
                continue;
            }

            $eligibleItems[] = [$item, $weight];
            $totalWeight += $weight;
        }

        if ($eligibleItems === [] || ! is_finite($totalWeight)) {
            throw new NoWeightedCandidatesException('没有可供抽取的正权重候选。');
        }

        $target = $this->randomSource->next($totalWeight);

        if (! is_finite($target) || $target < 0.0 || $target >= $totalWeight) {
            throw new UnexpectedValueException('随机源返回了有效区间之外的值。');
        }

        $cumulativeWeight = 0.0;

        foreach ($eligibleItems as [$item, $weight]) {
            $cumulativeWeight += $weight;

            if ($target < $cumulativeWeight) {
                return $item;
            }
        }

        return $eligibleItems[array_key_last($eligibleItems)][0];
    }
}
