<?php

namespace Tests\Feature;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\LotteryCandidateSnapshotService;
use App\Models\DrawRound;
use App\Models\DrawWinner;
use App\Models\Photo;
use App\Models\PhotoLike;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Tests\TestCase;

class LotteryCandidateSnapshotServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_freezes_current_weights_and_excludes_previous_winners(): void
    {
        config(['lottery.exclude_previous_winners' => true]);

        $previousWinner = User::factory()->create();
        $previousWinningPhoto = Photo::factory()->for($previousWinner)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);
        DrawWinner::factory()
            ->for(DrawRound::factory())
            ->for($previousWinningPhoto)
            ->create(['user_id' => $previousWinner->id]);

        $eligibleUser = User::factory()->create();
        $eligiblePhoto = Photo::factory()->for($eligibleUser)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
        ]);
        $firstVoter = User::factory()->create();
        PhotoLike::factory()->for($eligiblePhoto)->for($eligibleUser)->create();
        PhotoLike::factory()->for($eligiblePhoto)->for($firstVoter)->create();
        $drawRound = DrawRound::factory()->create(['draw_count' => 2]);

        $frozenRound = app(LotteryCandidateSnapshotService::class)->freeze($drawRound);

        $expectedSnapshot = [
            'total_weight' => 1.075,
            'users' => [
                [
                    'user_id' => $eligibleUser->id,
                    'phone_suffix' => substr($eligibleUser->phone, -4),
                    'weight' => 1.075,
                    'photos' => [
                        [
                            'photo_id' => $eligiblePhoto->id,
                            'object_key' => $eligiblePhoto->object_key,
                            'lottery_status' => 'approved',
                            'effective_likes' => 0.25,
                            'like_bonus' => 0.075,
                            'weight' => 1.075,
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedSnapshot, $frozenRound->candidate_snapshot);
        $this->assertSame([
            'draw_count' => 2,
            'exclude_previous_winners' => true,
            'selection_mode' => 'user_then_photo',
        ], $frozenRound->settings_snapshot);

        $secondVoter = User::factory()->create();
        PhotoLike::factory()->for($eligiblePhoto)->for($secondVoter)->create();
        $newRound = app(LotteryCandidateSnapshotService::class)->freeze(
            DrawRound::factory()->create(['draw_count' => 2]),
        );

        $this->assertSame($expectedSnapshot, $frozenRound->refresh()->candidate_snapshot);
        $this->assertSame(1.106066, $newRound->candidate_snapshot['total_weight']);
    }

    public function test_keeps_previous_winners_when_exclusion_is_disabled(): void
    {
        config(['lottery.exclude_previous_winners' => false]);

        $user = User::factory()->create();
        $photo = Photo::factory()->for($user)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
        ]);
        DrawWinner::factory()
            ->for(DrawRound::factory())
            ->for($photo)
            ->create(['user_id' => $user->id]);
        $drawRound = DrawRound::factory()->create();

        $frozenRound = app(LotteryCandidateSnapshotService::class)->freeze($drawRound);

        $this->assertSame([$user->id], array_column(
            $frozenRound->candidate_snapshot['users'],
            'user_id',
        ));
        $this->assertFalse($frozenRound->settings_snapshot['exclude_previous_winners']);
    }

    public function test_rejects_freezing_the_same_round_twice(): void
    {
        $drawRound = DrawRound::factory()->create();
        $service = app(LotteryCandidateSnapshotService::class);
        $service->freeze($drawRound);

        $this->expectException(LogicException::class);

        $service->freeze($drawRound);
    }
}
