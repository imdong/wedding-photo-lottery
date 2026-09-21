<?php

namespace App\Enums;

enum DrawRoundStatus: string
{
    case Prepared = 'prepared';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
