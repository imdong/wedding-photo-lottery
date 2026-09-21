<?php

namespace App;

use Illuminate\Support\Collection;

final readonly class LotteryCandidatePool
{
    /**
     * @param  Collection<int, LotteryUserCandidate>  $users
     */
    public function __construct(
        public Collection $users,
        public float $totalWeight,
    ) {}

    /** @return Collection<int, CalculatedPhotoLotteryWeight> */
    public function photos(): Collection
    {
        return $this->users
            ->flatMap(
                static fn (LotteryUserCandidate $user): Collection => $user->photos,
            )
            ->keyBy(
                static fn (CalculatedPhotoLotteryWeight $photo): int => $photo->photo->id,
            )
            ->sortKeys();
    }
}
