<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\AdminTokenService;
use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPhotoReviewControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.token_ttl_minutes' => 480]);
    }

    public function test_returns_401_when_admin_token_is_missing(): void
    {
        $photo = Photo::factory()->create();

        $this->postJson("/api/admin/photos/{$photo->id}/review", ['status' => 'good'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'admin_unauthenticated');
    }

    public function test_returns_404_when_photo_does_not_exist(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/admin/photos/999/review', ['status' => 'good'])
            ->assertNotFound();
    }

    #[DataProvider('invalidStatusPayloads')]
    public function test_returns_422_for_invalid_review_status(array $payload, string $expectedMessage): void
    {
        $photo = Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Pending,
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/photos/{$photo->id}/review", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.status.0', $expectedMessage);

        $photo->refresh();
        $this->assertSame(PhotoReviewStatus::Pending, $photo->review_status);
        $this->assertNull($photo->reviewed_at);
    }

    #[DataProvider('reviewStatuses')]
    public function test_reviews_uploaded_pending_photo_and_records_review_time(PhotoReviewStatus $reviewStatus): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        $photo = Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Pending,
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/photos/{$photo->id}/review", [
                'status' => $reviewStatus->value,
                'reviewed_at' => '2020-01-01T00:00:00Z',
                'upload_status' => PhotoUploadStatus::Failed->value,
            ])
            ->assertOk()
            ->assertExactJson([
                'id' => $photo->id,
                'review_status' => $reviewStatus->value,
                'reviewed_at' => '2026-09-20T08:00:00.000000Z',
            ]);

        $photo->refresh();
        $this->assertSame(PhotoUploadStatus::Uploaded, $photo->upload_status);
        $this->assertSame($reviewStatus, $photo->review_status);
        $this->assertSame('2026-09-20T08:00:00.000000Z', $photo->reviewed_at?->toISOString());
    }

    #[DataProvider('incompleteUploadStatuses')]
    public function test_returns_409_when_photo_upload_is_incomplete(PhotoUploadStatus $uploadStatus): void
    {
        $photo = Photo::factory()->create([
            'upload_status' => $uploadStatus,
            'review_status' => PhotoReviewStatus::Pending,
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/photos/{$photo->id}/review", ['status' => 'good'])
            ->assertConflict()
            ->assertExactJson([
                'message' => '这张照片当前不可审核，请刷新后重试。',
                'code' => 'photo_not_pending_review',
            ]);

        $photo->refresh();
        $this->assertSame(PhotoReviewStatus::Pending, $photo->review_status);
        $this->assertNull($photo->reviewed_at);
    }

    public function test_returns_409_when_photo_has_already_been_reviewed(): void
    {
        $photo = Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
            'reviewed_at' => '2026-09-20 07:00:00',
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/photos/{$photo->id}/review", ['status' => 'rejected'])
            ->assertConflict()
            ->assertJsonPath('code', 'photo_not_pending_review');

        $photo->refresh();
        $this->assertSame(PhotoReviewStatus::Good, $photo->review_status);
        $this->assertSame('2026-09-20T07:00:00.000000Z', $photo->reviewed_at?->toISOString());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidStatusPayloads(): array
    {
        return [
            'missing status' => [[], '请选择审核结果。'],
            'pending is not a review result' => [['status' => 'pending'], '审核结果无效。'],
            'unknown status' => [['status' => 'featured'], '审核结果无效。'],
            'non-string status' => [['status' => 1], '审核结果无效。'],
        ];
    }

    /** @return array<string, array{PhotoReviewStatus}> */
    public static function reviewStatuses(): array
    {
        return [
            'normal' => [PhotoReviewStatus::Normal],
            'good' => [PhotoReviewStatus::Good],
            'rejected' => [PhotoReviewStatus::Rejected],
        ];
    }

    /** @return array<string, array{PhotoUploadStatus}> */
    public static function incompleteUploadStatuses(): array
    {
        return [
            'preparing' => [PhotoUploadStatus::Preparing],
            'failed' => [PhotoUploadStatus::Failed],
        ];
    }

    private function adminToken(): string
    {
        return app(AdminTokenService::class)->issue()['token'];
    }
}
