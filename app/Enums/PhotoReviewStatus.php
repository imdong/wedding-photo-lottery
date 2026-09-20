<?php

namespace App\Enums;

enum PhotoReviewStatus: string
{
    case Pending = 'pending';
    case Normal = 'normal';
    case Good = 'good';
    case Rejected = 'rejected';
}
