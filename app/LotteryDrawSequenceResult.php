<?php

namespace App;

final readonly class LotteryDrawSequenceResult
{
    /**
     * @param  list<LotterySelection>  $selections
     * @param  array<string, mixed>  $remainingSnapshot
     */
    public function __construct(
        public array $selections,
        public array $remainingSnapshot,
    ) {}
}
