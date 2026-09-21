<?php

namespace App;

final readonly class LotteryWinnerSelector
{
    public function __construct(
        private WeightedRandomSelector $weightedRandomSelector,
    ) {}

    /**
     * @param  array<string, mixed>  $candidateSnapshot
     */
    public function select(array $candidateSnapshot): LotterySelection
    {
        $user = $this->weightedRandomSelector->select(
            $candidateSnapshot['users'] ?? [],
            static fn (array $candidate): float => (float) ($candidate['weight'] ?? 0.0),
        );
        $photo = $this->weightedRandomSelector->select(
            $user['photos'] ?? [],
            static fn (array $candidate): float => (float) ($candidate['weight'] ?? 0.0),
        );

        return new LotterySelection($user, $photo);
    }
}
