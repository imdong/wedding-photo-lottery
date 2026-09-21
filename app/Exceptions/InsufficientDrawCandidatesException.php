<?php

namespace App\Exceptions;

use Exception;

final class InsufficientDrawCandidatesException extends Exception
{
    public function __construct(
        public readonly int $requiredCount,
        public readonly int $availableCount,
    ) {
        parent::__construct('可参与抽奖的人数不足。');
    }
}
