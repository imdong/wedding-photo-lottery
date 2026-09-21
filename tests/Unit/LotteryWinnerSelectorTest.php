<?php

namespace Tests\Unit;

use App\LotteryWinnerSelector;
use App\WeightedRandomSelector;
use Tests\Support\SequenceLotteryRandomSource;
use Tests\TestCase;

class LotteryWinnerSelectorTest extends TestCase
{
    public function test_selects_user_then_one_of_that_users_photos(): void
    {
        $selector = new LotteryWinnerSelector(
            new WeightedRandomSelector(
                new SequenceLotteryRandomSource([0.5, 0.75]),
            ),
        );
        $snapshot = [
            'users' => [
                [
                    'user_id' => 1,
                    'weight' => 0.0,
                    'photos' => [['photo_id' => 10, 'weight' => 10.0]],
                ],
                [
                    'user_id' => 2,
                    'weight' => 1.0,
                    'photos' => [['photo_id' => 20, 'weight' => 1.0]],
                ],
                [
                    'user_id' => 3,
                    'weight' => 3.0,
                    'photos' => [
                        ['photo_id' => 30, 'weight' => 0.0],
                        ['photo_id' => 31, 'weight' => 1.0],
                        ['photo_id' => 32, 'weight' => 3.0],
                    ],
                ],
            ],
        ];

        $selection = $selector->select($snapshot);

        $this->assertSame(3, $selection->user['user_id']);
        $this->assertSame(32, $selection->photo['photo_id']);
    }
}
