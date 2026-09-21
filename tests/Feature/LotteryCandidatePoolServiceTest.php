<?php

namespace Tests\Feature;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\LotteryCandidatePoolService;
use App\Models\Photo;
use App\Models\PhotoLike;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LotteryCandidatePoolServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_groups_weighted_photos_by_user_and_outputs_pool_total_weight(): void
    {
        $multiPhotoUser = User::factory()->create();
        $singlePhotoUser = User::factory()->create();
        $noApprovedPhotoVoter = User::factory()->create();

        $normalPhoto = Photo::factory()->for($multiPhotoUser)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
        ]);
        $featuredPhoto = Photo::factory()->for($multiPhotoUser)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);
        $likedPhoto = Photo::factory()->for($singlePhotoUser)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
        ]);

        foreach ([$singlePhotoUser, $multiPhotoUser, $noApprovedPhotoVoter] as $voter) {
            PhotoLike::factory()->for($likedPhoto)->for($voter)->create();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $pool = app(LotteryCandidatePoolService::class)->build();
        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        $expectedLikedPhotoBonus = 0.15 * sqrt(1.5);
        $expectedLikedPhotoWeight = 1.0 + $expectedLikedPhotoBonus;
        $expectedPoolWeight = 2.8 + $expectedLikedPhotoWeight;

        $this->assertSame([$multiPhotoUser->id, $singlePhotoUser->id], $pool->users->keys()->all());
        $this->assertSame([$normalPhoto->id, $featuredPhoto->id, $likedPhoto->id], $pool->photos()->keys()->all());
        $this->assertSame(4, $queryCount);
        $this->assertEqualsWithDelta($expectedPoolWeight, $pool->totalWeight, 0.000001);

        $multiPhotoCandidate = $pool->users->get($multiPhotoUser->id);
        $this->assertNotNull($multiPhotoCandidate);
        $this->assertTrue($multiPhotoUser->is($multiPhotoCandidate->user));
        $this->assertSame([$normalPhoto->id, $featuredPhoto->id], $multiPhotoCandidate->photos->keys()->all());
        $this->assertEqualsWithDelta(2.8, $multiPhotoCandidate->weight, 0.000001);
        $this->assertEqualsWithDelta(2.8 / $expectedPoolWeight, $multiPhotoCandidate->probability($pool->totalWeight), 0.000001);

        $singlePhotoCandidate = $pool->users->get($singlePhotoUser->id);
        $this->assertNotNull($singlePhotoCandidate);
        $this->assertSame([$likedPhoto->id], $singlePhotoCandidate->photos->keys()->all());
        $this->assertEqualsWithDelta($expectedLikedPhotoWeight, $singlePhotoCandidate->weight, 0.000001);

        $weightedLikedPhoto = $singlePhotoCandidate->photos->get($likedPhoto->id);
        $this->assertNotNull($weightedLikedPhoto);
        $this->assertSame(2, $weightedLikedPhoto->likeInfluence->validLikeCount());
        $this->assertEqualsWithDelta(1.5, $weightedLikedPhoto->likeInfluence->totalContribution(), 0.000001);
        $this->assertEqualsWithDelta(1.5, $weightedLikedPhoto->weight->effectiveLikes, 0.000001);
        $this->assertEqualsWithDelta($expectedLikedPhotoBonus, $weightedLikedPhoto->weight->likeBonus, 0.000001);
        $this->assertEqualsWithDelta($expectedLikedPhotoWeight, $weightedLikedPhoto->weight->weight, 0.000001);
        $this->assertSame(1.0, $weightedLikedPhoto->weight->probability($singlePhotoCandidate->weight));
    }

    public function test_returns_an_empty_pool_when_there_are_no_candidate_photos(): void
    {
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Rejected,
        ]);

        $pool = app(LotteryCandidatePoolService::class)->build();

        $this->assertTrue($pool->users->isEmpty());
        $this->assertTrue($pool->photos()->isEmpty());
        $this->assertSame(0.0, $pool->totalWeight);
    }

    public function test_only_uses_highest_bonus_photos_when_user_exceeds_candidate_limit(): void
    {
        config(['lottery.max_photos_per_user' => 15]);

        $owner = User::factory()->create();
        $voter = User::factory()->create();

        Photo::factory()->for($voter)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);

        $photoWithoutLikes = Photo::factory()->for($owner)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);

        $likedPhotos = Photo::factory()->count(15)->for($owner)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);

        $likedPhotos->each(function (Photo $photo) use ($voter): void {
            PhotoLike::factory()->for($photo)->for($voter)->create();
        });

        $pool = app(LotteryCandidatePoolService::class)->build();
        $ownerCandidate = $pool->users->get($owner->id);

        $this->assertNotNull($ownerCandidate);
        $this->assertCount(15, $ownerCandidate->photos);
        $this->assertFalse($ownerCandidate->photos->has($photoWithoutLikes->id));
        $this->assertEqualsCanonicalizing(
            $likedPhotos->pluck('id')->all(),
            $ownerCandidate->photos->keys()->all(),
        );
    }
}
