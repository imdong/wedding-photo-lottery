<?php

namespace Tests\Feature;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\Models\PhotoLike;
use App\Models\User;
use App\PhotoLikeInfluenceService;
use App\PhotoLotteryWeight;
use App\PhotoWeightBatchService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhotoWeightBatchServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_calculates_all_eligible_photos_consistently_without_per_photo_queries(): void
    {
        $normalOwner = User::factory()->create();
        $featuredOwner = User::factory()->create();
        $normalPhoto = Photo::factory()->for($normalOwner)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
        ]);
        $featuredPhoto = Photo::factory()->for($featuredOwner)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);

        foreach ([PhotoReviewStatus::Pending, PhotoReviewStatus::Rejected] as $reviewStatus) {
            Photo::factory()->create([
                'upload_status' => PhotoUploadStatus::Uploaded,
                'review_status' => $reviewStatus,
            ]);
        }

        foreach ([PhotoUploadStatus::Preparing, PhotoUploadStatus::Failed] as $uploadStatus) {
            Photo::factory()->create([
                'upload_status' => $uploadStatus,
                'review_status' => PhotoReviewStatus::Good,
            ]);
        }

        $noApprovedPhotoVoter = User::factory()->create();

        foreach ([$normalOwner, $noApprovedPhotoVoter, $featuredOwner] as $voter) {
            PhotoLike::factory()->for($normalPhoto)->for($voter)->create();
        }

        foreach ([$featuredOwner, $normalOwner, $noApprovedPhotoVoter] as $voter) {
            PhotoLike::factory()->for($featuredPhoto)->for($voter)->create();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $results = app(PhotoWeightBatchService::class)->calculate();
        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame([$normalPhoto->id, $featuredPhoto->id], $results->keys()->all());
        $this->assertSame(3, $queryCount);

        $singlePhotoService = app(PhotoLikeInfluenceService::class);

        foreach ([$normalPhoto, $featuredPhoto] as $photo) {
            $expectedInfluence = $singlePhotoService->summarize($photo);
            $expectedWeight = PhotoLotteryWeight::calculate(
                $photo->review_status,
                $expectedInfluence->voterTiers,
            );
            $result = $results->get($photo->id);

            $this->assertNotNull($result);
            $this->assertTrue($photo->is($result->photo));
            $this->assertSame($expectedInfluence->voterTiers, $result->likeInfluence->voterTiers);
            $this->assertSame($expectedWeight->status, $result->weight->status);
            $this->assertEqualsWithDelta($expectedWeight->effectiveLikes, $result->weight->effectiveLikes, 0.000001);
            $this->assertEqualsWithDelta($expectedWeight->likeBonus, $result->weight->likeBonus, 0.000001);
            $this->assertEqualsWithDelta($expectedWeight->weight, $result->weight->weight, 0.000001);
        }
    }

    public function test_returns_an_empty_collection_when_there_are_no_eligible_photos(): void
    {
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Pending,
        ]);

        $this->assertTrue(app(PhotoWeightBatchService::class)->calculate()->isEmpty());
    }
}
