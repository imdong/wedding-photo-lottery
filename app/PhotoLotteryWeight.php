<?php

namespace App;

use App\Enums\LikeVoterTier;
use App\Enums\LotteryPhotoStatus;
use App\Enums\PhotoReviewStatus;

final readonly class PhotoLotteryWeight
{
    public const MAX_EFFECTIVE_LIKES = 16.0;

    public const LIKE_BONUS_FACTOR = 0.15;

    private function __construct(
        public LotteryPhotoStatus $status,
        public float $effectiveLikes,
        public float $likeBonus,
        public float $weight,
    ) {}

    /**
     * @param  iterable<LikeVoterTier>  $likeVoterTiers
     */
    public static function calculate(
        PhotoReviewStatus $reviewStatus,
        iterable $likeVoterTiers = [],
    ): self {
        $lotteryStatus = LotteryPhotoStatus::fromReviewStatus($reviewStatus);

        if ($lotteryStatus === LotteryPhotoStatus::Ignored) {
            return new self($lotteryStatus, 0.0, 0.0, 0.0);
        }

        $effectiveLikes = 0.0;

        foreach ($likeVoterTiers as $likeVoterTier) {
            $effectiveLikes = min(
                self::MAX_EFFECTIVE_LIKES,
                $effectiveLikes + $likeVoterTier->multiplier(),
            );

            if ($effectiveLikes === self::MAX_EFFECTIVE_LIKES) {
                break;
            }
        }

        $likeBonus = self::LIKE_BONUS_FACTOR * sqrt($effectiveLikes);

        return new self(
            $lotteryStatus,
            $effectiveLikes,
            $likeBonus,
            $lotteryStatus->baseWeight() + $likeBonus,
        );
    }

    public function probability(float $totalPhotoWeight): float
    {
        if ($this->weight === 0.0 || $totalPhotoWeight <= 0.0) {
            return 0.0;
        }

        return $this->weight / $totalPhotoWeight;
    }
}
