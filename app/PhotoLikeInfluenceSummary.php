<?php

namespace App;

use App\Enums\LikeVoterTier;

final readonly class PhotoLikeInfluenceSummary
{
    /**
     * @param  list<LikeVoterTier>  $voterTiers
     */
    public function __construct(public array $voterTiers) {}

    public function validLikeCount(): int
    {
        return count($this->voterTiers);
    }

    public function countFor(LikeVoterTier $tier): int
    {
        return count(array_filter(
            $this->voterTiers,
            fn (LikeVoterTier $voterTier): bool => $voterTier === $tier,
        ));
    }

    public function totalContribution(): float
    {
        return array_reduce(
            $this->voterTiers,
            fn (float $total, LikeVoterTier $tier): float => $total + $tier->multiplier(),
            0.0,
        );
    }
}
