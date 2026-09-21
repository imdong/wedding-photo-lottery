<?php

namespace App;

use InvalidArgumentException;

final class SecureLotteryRandomSource implements LotteryRandomSource
{
    public function next(float $maximumExclusive): float
    {
        if (! is_finite($maximumExclusive) || $maximumExclusive <= 0.0) {
            throw new InvalidArgumentException('随机数上限必须是有限正数。');
        }

        $ratio = random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;

        return $ratio * $maximumExclusive;
    }
}
