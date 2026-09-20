<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhotoLikeControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_401_when_token_is_missing(): void
    {
        $photo = Photo::factory()->create();

        $this->postJson("/api/photos/{$photo->id}/like")
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_returns_404_when_photo_does_not_exist(): void
    {
        [, $plainTextToken] = $this->authenticatedUser();

        $this->withToken($plainTextToken)
            ->postJson('/api/photos/999/like')
            ->assertNotFound();
    }

    #[DataProvider('approvedStatuses')]
    public function test_likes_approved_photo_idempotently(PhotoReviewStatus $reviewStatus): void
    {
        [$user, $plainTextToken] = $this->authenticatedUser();
        $photo = Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => $reviewStatus,
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withToken($plainTextToken)
                ->postJson("/api/photos/{$photo->id}/like")
                ->assertOk()
                ->assertExactJson([
                    'photo_id' => $photo->id,
                    'liked' => true,
                ]);
        }

        $this->assertDatabaseCount('photo_likes', 1);
        $this->assertDatabaseHas('photo_likes', [
            'photo_id' => $photo->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_rejects_liking_own_photo(): void
    {
        [$user, $plainTextToken] = $this->authenticatedUser();
        $photo = Photo::factory()->for($user)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);

        $this->withToken($plainTextToken)
            ->postJson("/api/photos/{$photo->id}/like")
            ->assertForbidden()
            ->assertExactJson([
                'message' => '不能给自己上传的照片点赞。',
                'code' => 'cannot_like_own_photo',
            ]);

        $this->assertDatabaseCount('photo_likes', 0);
    }

    #[DataProvider('unlikeablePhotoStates')]
    public function test_rejects_photo_that_is_not_uploaded_and_approved(
        PhotoUploadStatus $uploadStatus,
        PhotoReviewStatus $reviewStatus,
    ): void {
        [, $plainTextToken] = $this->authenticatedUser();
        $photo = Photo::factory()->create([
            'upload_status' => $uploadStatus,
            'review_status' => $reviewStatus,
        ]);

        $this->withToken($plainTextToken)
            ->postJson("/api/photos/{$photo->id}/like")
            ->assertConflict()
            ->assertExactJson([
                'message' => '这张照片当前不能点赞。',
                'code' => 'photo_not_likeable',
            ]);

        $this->assertDatabaseCount('photo_likes', 0);
    }

    /** @return array<string, array{PhotoReviewStatus}> */
    public static function approvedStatuses(): array
    {
        return [
            'normal' => [PhotoReviewStatus::Normal],
            'good' => [PhotoReviewStatus::Good],
        ];
    }

    /** @return array<string, array{PhotoUploadStatus, PhotoReviewStatus}> */
    public static function unlikeablePhotoStates(): array
    {
        return [
            'pending review' => [PhotoUploadStatus::Uploaded, PhotoReviewStatus::Pending],
            'rejected review' => [PhotoUploadStatus::Uploaded, PhotoReviewStatus::Rejected],
            'preparing upload' => [PhotoUploadStatus::Preparing, PhotoReviewStatus::Good],
            'failed upload' => [PhotoUploadStatus::Failed, PhotoReviewStatus::Normal],
        ];
    }

    /** @return array{User, string} */
    private function authenticatedUser(): array
    {
        $plainTextToken = 'valid-photo-like-token';
        $user = User::factory()->create([
            'access_token_hash' => hash('sha256', $plainTextToken),
        ]);

        return [$user, $plainTextToken];
    }
}
