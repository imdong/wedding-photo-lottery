<?php

namespace App;

use App\Models\User;
use Illuminate\Support\Collection;

final readonly class LotteryUserCandidate
{
    /**
     * @param  Collection<int, CalculatedPhotoLotteryWeight>  $photos
     */
    private function __construct(
        public User $user,
        public Collection $photos,
        public float $weight,
    ) {}

    /**
     * @param  Collection<int, CalculatedPhotoLotteryWeight>  $photos
     */
    public static function from(User $user, Collection $photos): self
    {
        return new self(
            user: $user,
            photos: $photos,
            weight: (float) $photos->sum(
                static fn (CalculatedPhotoLotteryWeight $photo): float => $photo->weight->weight,
            ),
        );
    }

    public function probability(float $totalPoolWeight): float
    {
        if ($this->weight === 0.0 || $totalPoolWeight <= 0.0) {
            return 0.0;
        }

        return $this->weight / $totalPoolWeight;
    }
}
