<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\AdminTokenService;
use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\AdminReviewLog;
use App\Models\Photo;
use DateTimeInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UndoAdminReviewControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.token_ttl_minutes' => 480]);
    }

    public function test_returns_401_when_admin_token_is_missing(): void
    {
        $this->postJson('/api/admin/reviews/undo')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'admin_unauthenticated');
    }

    public function test_returns_409_when_there_is_no_review_to_undo(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/admin/reviews/undo')
            ->assertConflict()
            ->assertExactJson([
                'message' => '没有可撤销的审核记录。',
                'code' => 'nothing_to_undo',
            ]);
    }

    public function test_undoes_latest_review_and_returns_photo_to_pending_queue(): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        $firstPhoto = $this->reviewedPhoto(PhotoReviewStatus::Normal);
        $latestPhoto = $this->reviewedPhoto(PhotoReviewStatus::Good);
        $firstLog = $this->reviewLog($firstPhoto, PhotoReviewStatus::Normal);
        $latestLog = $this->reviewLog($latestPhoto, PhotoReviewStatus::Good);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/reviews/undo')
            ->assertOk()
            ->assertExactJson([
                'id' => $latestPhoto->id,
                'review_status' => PhotoReviewStatus::Pending->value,
                'reviewed_at' => null,
            ]);

        $firstPhoto->refresh();
        $latestPhoto->refresh();
        $firstLog->refresh();
        $latestLog->refresh();

        $this->assertSame(PhotoReviewStatus::Normal, $firstPhoto->review_status);
        $this->assertNotNull($firstPhoto->reviewed_at);
        $this->assertNull($firstLog->undone_at);
        $this->assertSame(PhotoReviewStatus::Pending, $latestPhoto->review_status);
        $this->assertNull($latestPhoto->reviewed_at);
        $this->assertSame('2026-09-20T08:00:00.000000Z', $latestLog->undone_at?->toISOString());
        $this->assertTrue(Photo::query()
            ->whereKey($latestPhoto)
            ->where('upload_status', PhotoUploadStatus::Uploaded)
            ->where('review_status', PhotoReviewStatus::Pending)
            ->exists());
    }

    public function test_ignores_already_undone_logs_when_finding_latest_review(): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        $activePhoto = $this->reviewedPhoto(PhotoReviewStatus::Normal);
        $alreadyUndonePhoto = Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Pending,
        ]);
        $activeLog = $this->reviewLog($activePhoto, PhotoReviewStatus::Normal);
        $alreadyUndoneLog = $this->reviewLog($alreadyUndonePhoto, PhotoReviewStatus::Good, now());

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/reviews/undo')
            ->assertOk()
            ->assertJsonPath('id', $activePhoto->id);

        $this->assertNotNull($activeLog->refresh()->undone_at);
        $this->assertSame('2026-09-20T08:00:00.000000Z', $alreadyUndoneLog->refresh()->undone_at?->toISOString());
        $this->assertSame(PhotoReviewStatus::Pending, $activePhoto->refresh()->review_status);
    }

    public function test_returns_409_without_changes_when_photo_state_no_longer_matches_log(): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        $photo = $this->reviewedPhoto(PhotoReviewStatus::Rejected);
        $reviewLog = $this->reviewLog($photo, PhotoReviewStatus::Good);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/reviews/undo')
            ->assertConflict()
            ->assertExactJson([
                'message' => '最近审核的照片状态已经变化，请刷新后重试。',
                'code' => 'review_state_changed',
            ]);

        $this->assertSame(PhotoReviewStatus::Rejected, $photo->refresh()->review_status);
        $this->assertNotNull($photo->reviewed_at);
        $this->assertNull($reviewLog->refresh()->undone_at);
    }

    private function reviewedPhoto(PhotoReviewStatus $reviewStatus): Photo
    {
        return Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => $reviewStatus,
            'reviewed_at' => now(),
        ]);
    }

    private function reviewLog(
        Photo $photo,
        PhotoReviewStatus $newStatus,
        ?DateTimeInterface $undoneAt = null,
    ): AdminReviewLog {
        return AdminReviewLog::factory()->for($photo)->create([
            'previous_status' => PhotoReviewStatus::Pending,
            'new_status' => $newStatus,
            'created_at' => now(),
            'undone_at' => $undoneAt,
        ]);
    }

    private function adminToken(): string
    {
        return app(AdminTokenService::class)->issue()['token'];
    }
}
