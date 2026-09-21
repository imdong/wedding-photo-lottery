<?php

namespace App;

use App\Models\Photo;

final readonly class CalculatedPhotoLotteryWeight
{
    public function __construct(
        public Photo $photo,
        public PhotoLikeInfluenceSummary $likeInfluence,
        public PhotoLotteryWeight $weight,
    ) {}
}
