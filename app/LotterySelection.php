<?php

namespace App;

final readonly class LotterySelection
{
    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $photo
     */
    public function __construct(
        public array $user,
        public array $photo,
    ) {}
}
