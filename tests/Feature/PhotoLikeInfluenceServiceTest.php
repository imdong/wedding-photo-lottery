<?php

namespace Tests\Feature;

use App\Enums\LikeVoterTier;
use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\Models\PhotoLike;
use App\Models\User;
use App\PhotoLikeInfluenceService;
use App\PhotoLotteryWeight;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PhotoLikeInfluenceServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_summarizes_current_voter_tiers_and_excludes_photo_owner(): void
    {
        $owner = User::factory()->create();
        $photo = Photo::factory()->for($owner)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);
        $noApprovedPhotoVoter = User::factory()->create();
        $approvedVoter = User::factory()->create();
        $featuredVoter = User::factory()->create();
        $incompleteFeaturedVoter = User::factory()->create();

        Photo::factory()->for($noApprovedPhotoVoter)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Pending,
        ]);
        Photo::factory()->for($approvedVoter)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
        ]);
        Photo::factory()->for($featuredVoter)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);
        Photo::factory()->for($incompleteFeaturedVoter)->create([
            'upload_status' => PhotoUploadStatus::Preparing,
            'review_status' => PhotoReviewStatus::Good,
        ]);

        foreach ([$owner, $noApprovedPhotoVoter, $approvedVoter, $featuredVoter, $incompleteFeaturedVoter] as $voter) {
            PhotoLike::factory()->for($photo)->for($voter)->create();
        }

        $summary = app(PhotoLikeInfluenceService::class)->summarize($photo);

        $this->assertSame(4, $summary->validLikeCount());
        $this->assertSame(2, $summary->countFor(LikeVoterTier::NoApprovedPhoto));
        $this->assertSame(1, $summary->countFor(LikeVoterTier::Approved));
        $this->assertSame(1, $summary->countFor(LikeVoterTier::Featured));
        $this->assertSame(2.75, $summary->totalContribution());

        $weight = PhotoLotteryWeight::calculate($photo->review_status, $summary->voterTiers);

        $this->assertSame(2.75, $weight->effectiveLikes);
    }

    public function test_uses_voters_current_photo_status_instead_of_like_time_status(): void
    {
        $photo = Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
        ]);
        $voter = User::factory()->create();
        PhotoLike::factory()->for($photo)->for($voter)->create();
        $service = app(PhotoLikeInfluenceService::class);

        $this->assertSame(
            [LikeVoterTier::NoApprovedPhoto],
            $service->summarize($photo)->voterTiers,
        );

        $votersPhoto = Photo::factory()->for($voter)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
        ]);

        $this->assertSame(
            [LikeVoterTier::Approved],
            $service->summarize($photo)->voterTiers,
        );

        $votersPhoto->update(['review_status' => PhotoReviewStatus::Good]);

        $this->assertSame(
            [LikeVoterTier::Featured],
            $service->summarize($photo)->voterTiers,
        );
    }
}
