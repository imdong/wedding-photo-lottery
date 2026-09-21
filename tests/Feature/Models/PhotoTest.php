<?php

namespace Tests\Feature\Models;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhotoTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_preparing_photo_can_be_created_with_default_statuses(): void
    {
        $user = User::factory()->create();

        $photo = Photo::factory()
            ->for($user)
            ->create([
                'object_key' => 'photos/1/original.jpg',
                'original_file_hash' => str_repeat('a', 64),
                'upload_file_hash' => str_repeat('b', 64),
            ]);

        $this->assertModelExists($photo);
        $this->assertSame(PhotoUploadStatus::Preparing, $photo->upload_status);
        $this->assertSame(PhotoReviewStatus::Pending, $photo->review_status);
        $this->assertNull($photo->reviewed_at);
        $this->assertFalse($photo->exclude_from_lottery);
        $this->assertTrue($photo->user->is($user));
        $this->assertTrue($user->photos()->whereKey($photo)->exists());
    }

    #[DataProvider('uniquePhotoIdentifiers')]
    public function test_duplicate_photo_identifiers_are_rejected(string $attribute, string $value): void
    {
        Photo::factory()->create([$attribute => $value]);

        $this->expectException(QueryException::class);

        Photo::factory()->create([$attribute => $value]);
    }

    public function test_deleting_user_deletes_owned_photos(): void
    {
        $user = User::factory()->create();
        $photo = Photo::factory()->for($user)->create();

        $user->delete();

        $this->assertModelMissing($photo);
    }

    /** @return array<string, array{string, string}> */
    public static function uniquePhotoIdentifiers(): array
    {
        return [
            'object key' => ['object_key', 'photos/shared/original.jpg'],
            'original file hash' => ['original_file_hash', str_repeat('b', 64)],
            'upload file hash' => ['upload_file_hash', str_repeat('c', 64)],
        ];
    }
}
