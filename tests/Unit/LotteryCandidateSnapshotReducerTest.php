<?php

namespace Tests\Unit;

use App\LotteryCandidateSnapshotReducer;
use Tests\TestCase;
use UnexpectedValueException;

class LotteryCandidateSnapshotReducerTest extends TestCase
{
    public function test_removes_the_users_entire_photo_group_and_recalculates_total_weight(): void
    {
        $snapshot = [
            'total_weight' => 6.0,
            'users' => [
                ['user_id' => 1, 'weight' => 1.0, 'photos' => [['photo_id' => 10]]],
                ['user_id' => 2, 'weight' => 2.0, 'photos' => [['photo_id' => 20], ['photo_id' => 21]]],
                ['user_id' => 3, 'weight' => 3.0, 'photos' => [['photo_id' => 30]]],
            ],
        ];

        $remainingSnapshot = (new LotteryCandidateSnapshotReducer)->removeUser($snapshot, 2);

        $this->assertSame([1, 3], array_column($remainingSnapshot['users'], 'user_id'));
        $this->assertSame(4.0, $remainingSnapshot['total_weight']);
        $this->assertSame([1, 2, 3], array_column($snapshot['users'], 'user_id'));
        $this->assertSame(6.0, $snapshot['total_weight']);
    }

    public function test_rejects_removing_a_user_outside_the_snapshot(): void
    {
        $snapshot = [
            'total_weight' => 1.0,
            'users' => [['user_id' => 1, 'weight' => 1.0, 'photos' => []]],
        ];

        $this->expectException(UnexpectedValueException::class);

        (new LotteryCandidateSnapshotReducer)->removeUser($snapshot, 99);
    }
}
