<?php

namespace App;

interface LotteryRandomSource
{
    /**
     * Return a random value in the half-open interval [0, $maximumExclusive).
     */
    public function next(float $maximumExclusive): float;
}
