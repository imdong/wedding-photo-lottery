<?php

namespace App\Enums;

enum LikeVoterTier: string
{
    case NoApprovedPhoto = 'no_approved_photo';
    case Approved = 'approved';
    case Featured = 'featured';

    public function multiplier(): float
    {
        return match ($this) {
            self::NoApprovedPhoto => 0.25,
            self::Approved => 1.0,
            self::Featured => 1.25,
        };
    }
}
