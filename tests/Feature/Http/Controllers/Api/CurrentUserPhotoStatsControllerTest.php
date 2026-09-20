<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CurrentUserPhotoStatsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_401_when_token_is_missing(): void
    {
        $this->getJson('/api/me/stats')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => '身份凭证无效，请重新输入手机号。',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_returns_zero_counts_when_current_user_has_no_photos(): void
    {
        $plainTextToken = 'user-without-photos-token';
        User::factory()->create([
            'access_token_hash' => hash('sha256', $plainTextToken),
        ]);

        $this->withToken($plainTextToken)
            ->getJson('/api/me/stats')
            ->assertOk()
            ->assertExactJson([
                'uploaded_count' => 0,
                'pending_count' => 0,
                'approved_count' => 0,
                'rejected_count' => 0,
            ]);
    }

    public function test_returns_uploaded_photo_statistics_for_current_user_only(): void
    {
        $plainTextToken = 'valid-user-stats-token';
        $user = User::factory()->create([
            'access_token_hash' => hash('sha256', $plainTextToken),
        ]);

        $this->createPhoto($user, PhotoUploadStatus::Uploaded, PhotoReviewStatus::Pending);
        $this->createPhoto($user, PhotoUploadStatus::Uploaded, PhotoReviewStatus::Normal);
        $this->createPhoto($user, PhotoUploadStatus::Uploaded, PhotoReviewStatus::Good);
        $this->createPhoto($user, PhotoUploadStatus::Uploaded, PhotoReviewStatus::Rejected);
        $this->createPhoto($user, PhotoUploadStatus::Preparing, PhotoReviewStatus::Pending);
        $this->createPhoto($user, PhotoUploadStatus::Failed, PhotoReviewStatus::Good);
        $this->createPhoto(User::factory()->create(), PhotoUploadStatus::Uploaded, PhotoReviewStatus::Good);

        $this->withToken($plainTextToken)
            ->getJson('/api/me/stats')
            ->assertOk()
            ->assertExactJson([
                'uploaded_count' => 4,
                'pending_count' => 1,
                'approved_count' => 2,
                'rejected_count' => 1,
            ]);
    }

    private function createPhoto(
        User $user,
        PhotoUploadStatus $uploadStatus,
        PhotoReviewStatus $reviewStatus,
    ): Photo {
        return Photo::factory()->for($user)->create([
            'upload_status' => $uploadStatus,
            'review_status' => $reviewStatus,
        ]);
    }
}
