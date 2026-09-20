<?php

namespace App\Enums;

enum LotteryPhotoStatus: string
{
    case Ignored = 'ignored';
    case Approved = 'approved';
    case Featured = 'featured';

    public static function fromReviewStatus(PhotoReviewStatus $reviewStatus): self
    {
        return match ($reviewStatus) {
            PhotoReviewStatus::Pending, PhotoReviewStatus::Rejected => self::Ignored,
            PhotoReviewStatus::Normal => self::Approved,
            PhotoReviewStatus::Good => self::Featured,
        };
    }

    public function baseWeight(): float
    {
        return match ($this) {
            self::Ignored => 0.0,
            self::Approved => 1.0,
            self::Featured => 1.8,
        };
    }
}
