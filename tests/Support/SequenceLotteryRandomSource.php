<?php

namespace Tests\Support;

use App\LotteryRandomSource;
use RuntimeException;

final class SequenceLotteryRandomSource implements LotteryRandomSource
{
    /** @param list<float> $ratios */
    public function __construct(private array $ratios) {}

    public function next(float $maximumExclusive): float
    {
        $ratio = array_shift($this->ratios);

        if ($ratio === null) {
            throw new RuntimeException('测试随机序列已经用尽。');
        }

        return $ratio * $maximumExclusive;
    }
}
