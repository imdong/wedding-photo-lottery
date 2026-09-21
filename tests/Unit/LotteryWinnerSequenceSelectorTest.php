<?php

namespace Tests\Unit;

use App\LotteryCandidateSnapshotReducer;
use App\LotterySelection;
use App\LotteryWinnerSelector;
use App\LotteryWinnerSequenceSelector;
use App\WeightedRandomSelector;
use InvalidArgumentException;
use Tests\Support\SequenceLotteryRandomSource;
use Tests\TestCase;
use UnexpectedValueException;

class LotteryWinnerSequenceSelectorTest extends TestCase
{
    public function test_continuous_selection_never_repeats_a_user_or_leaves_the_snapshot(): void
    {
        $selector = new LotteryWinnerSequenceSelector(
            new LotteryWinnerSelector(
                new WeightedRandomSelector(
                    new SequenceLotteryRandomSource([0.2, 0.0, 0.9, 0.9]),
                ),
            ),
            new LotteryCandidateSnapshotReducer,
        );
        $snapshot = [
            'total_weight' => 6.0,
            'users' => [
                [
                    'user_id' => 1,
                    'weight' => 1.0,
                    'photos' => [['photo_id' => 10, 'weight' => 1.0]],
                ],
                [
                    'user_id' => 2,
                    'weight' => 2.0,
                    'photos' => [
                        ['photo_id' => 20, 'weight' => 1.0],
                        ['photo_id' => 21, 'weight' => 1.0],
                    ],
                ],
                [
                    'user_id' => 3,
                    'weight' => 3.0,
                    'photos' => [
                        ['photo_id' => 30, 'weight' => 1.0],
                        ['photo_id' => 31, 'weight' => 2.0],
                    ],
                ],
            ],
        ];

        $result = $selector->select($snapshot, 2);

        $this->assertSame(
            [2, 3],
            array_map(
                static fn (LotterySelection $selection): int => $selection->user['user_id'],
                $result->selections,
            ),
        );
        $this->assertSame(
            [20, 31],
            array_map(
                static fn (LotterySelection $selection): int => $selection->photo['photo_id'],
                $result->selections,
            ),
        );
        $this->assertSame([1], array_column($result->remainingSnapshot['users'], 'user_id'));
        $this->assertSame(1.0, $result->remainingSnapshot['total_weight']);
        $this->assertSame([1, 2, 3], array_column($snapshot['users'], 'user_id'));
    }

    public function test_zero_selections_return_the_original_pool_unchanged(): void
    {
        $snapshot = [
            'total_weight' => 1.0,
            'users' => [['user_id' => 1, 'weight' => 1.0, 'photos' => []]],
        ];
        $selector = new LotteryWinnerSequenceSelector(
            new LotteryWinnerSelector(
                new WeightedRandomSelector(new SequenceLotteryRandomSource([])),
            ),
            new LotteryCandidateSnapshotReducer,
        );

        $result = $selector->select($snapshot, 0);

        $this->assertSame([], $result->selections);
        $this->assertSame($snapshot, $result->remainingSnapshot);
    }

    public function test_rejects_a_negative_selection_count(): void
    {
        $selector = new LotteryWinnerSequenceSelector(
            new LotteryWinnerSelector(
                new WeightedRandomSelector(new SequenceLotteryRandomSource([])),
            ),
            new LotteryCandidateSnapshotReducer,
        );

        $this->expectException(InvalidArgumentException::class);

        $selector->select(['users' => []], -1);
    }

    public function test_rejects_a_selected_candidate_without_a_user_id(): void
    {
        $selector = new LotteryWinnerSequenceSelector(
            new LotteryWinnerSelector(
                new WeightedRandomSelector(new SequenceLotteryRandomSource([0.0, 0.0])),
            ),
            new LotteryCandidateSnapshotReducer,
        );
        $snapshot = [
            'users' => [[
                'weight' => 1.0,
                'photos' => [['photo_id' => 10, 'weight' => 1.0]],
            ]],
        ];

        $this->expectException(UnexpectedValueException::class);

        $selector->select($snapshot, 1);
    }
}
