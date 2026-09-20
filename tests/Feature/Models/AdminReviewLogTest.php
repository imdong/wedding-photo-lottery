<?php

namespace Tests\Feature\Models;

use App\Enums\PhotoReviewStatus;
use App\Models\AdminReviewLog;
use App\Models\Photo;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminReviewLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_review_log_casts_statuses_and_belongs_to_photo(): void
    {
        $photo = Photo::factory()->create();

        $reviewLog = AdminReviewLog::factory()->for($photo)->create([
            'previous_status' => PhotoReviewStatus::Pending,
            'new_status' => PhotoReviewStatus::Good,
        ]);

        $this->assertModelExists($reviewLog);
        $this->assertSame(PhotoReviewStatus::Pending, $reviewLog->previous_status);
        $this->assertSame(PhotoReviewStatus::Good, $reviewLog->new_status);
        $this->assertTrue($reviewLog->photo->is($photo));
        $this->assertTrue($photo->reviewLogs()->whereKey($reviewLog)->exists());
        $this->assertNotNull($reviewLog->created_at);
    }

    public function test_deleting_photo_deletes_its_review_logs(): void
    {
        $photo = Photo::factory()->create();
        $reviewLog = AdminReviewLog::factory()->for($photo)->create();

        $photo->delete();

        $this->assertModelMissing($reviewLog);
    }
}
