<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\Models\PhotoLike;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GalleryPhotoControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'oss.access_key_id' => 'test-access-key-id',
            'oss.access_key_secret' => 'test-access-key-secret',
            'oss.bucket' => 'example-wedding-photos',
            'oss.public_url' => 'https://images.example.test',
            'oss.image_url_timezone' => 'Asia/Shanghai',
        ]);
    }

    public function test_returns_401_when_token_is_missing(): void
    {
        $this->getJson('/api/gallery')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_returns_only_uploaded_approved_photos_in_latest_first_order(): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        [, $plainTextToken] = $this->authenticatedUser();
        $normalPhoto = Photo::factory()->create([
            'object_key' => 'wedding/photos/normal.jpg',
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
            'created_at' => '2026-09-20 07:00:00',
        ]);
        $goodPhoto = Photo::factory()->create([
            'object_key' => 'wedding/photos/good.jpg',
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
            'created_at' => '2026-09-20 08:00:00',
        ]);
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Pending,
            'created_at' => '2026-09-20 10:00:00',
        ]);
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Rejected,
            'created_at' => '2026-09-20 09:00:00',
        ]);
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Preparing,
            'review_status' => PhotoReviewStatus::Good,
            'created_at' => '2026-09-20 11:00:00',
        ]);

        $this->withToken($plainTextToken)
            ->getJson('/api/gallery')
            ->assertOk()
            ->assertExactJson([
                'photos' => [
                    [
                        'id' => $goodPhoto->id,
                        'thumbnail_url' => $this->imageUrl($goodPhoto->object_key, 'image/resize,m_fill,w_400,h_400/quality,q_85/format,webp'),
                        'preview_url' => $this->imageUrl($goodPhoto->object_key, 'image/resize,l_1200/quality,q_90/format,webp'),
                        'like_count' => 0,
                        'liked_by_me' => false,
                        'is_mine' => false,
                        'can_like' => true,
                        'created_at' => '2026-09-20T08:00:00.000000Z',
                    ],
                    [
                        'id' => $normalPhoto->id,
                        'thumbnail_url' => $this->imageUrl($normalPhoto->object_key, 'image/resize,m_fill,w_400,h_400/quality,q_85/format,webp'),
                        'preview_url' => $this->imageUrl($normalPhoto->object_key, 'image/resize,l_1200/quality,q_90/format,webp'),
                        'like_count' => 0,
                        'liked_by_me' => false,
                        'is_mine' => false,
                        'can_like' => true,
                        'created_at' => '2026-09-20T07:00:00.000000Z',
                    ],
                ],
                'next_cursor' => null,
                'has_more' => false,
                'total_count' => 2,
            ]);
    }

    public function test_paginates_gallery_with_a_stable_cursor(): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        [$user, $plainTextToken] = $this->authenticatedUser();
        $photos = Photo::factory()->for($user)->count(21)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
            'created_at' => now(),
        ]);

        $firstPage = $this->withToken($plainTextToken)
            ->getJson('/api/gallery')
            ->assertOk()
            ->assertJsonCount(20, 'photos')
            ->assertJsonPath('photos.0.id', $photos->last()->id)
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('total_count', 21);
        $nextCursor = $firstPage->json('next_cursor');

        $this->assertIsString($nextCursor);
        $this->assertNotSame('', $nextCursor);

        $this->withToken($plainTextToken)
            ->getJson('/api/gallery?cursor='.urlencode($nextCursor))
            ->assertOk()
            ->assertJsonCount(1, 'photos')
            ->assertJsonPath('photos.0.id', $photos->first()->id)
            ->assertJsonPath('next_cursor', null)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('total_count', 21);
    }

    public function test_returns_only_valid_likes_and_current_users_like_state(): void
    {
        [$user, $plainTextToken] = $this->authenticatedUser();
        $anotherOwner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownedPhoto = Photo::factory()->for($user)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
            'created_at' => '2026-09-20 07:00:00',
        ]);
        $anotherUsersPhoto = Photo::factory()->for($anotherOwner)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
            'created_at' => '2026-09-20 08:00:00',
        ]);

        PhotoLike::factory()->for($anotherUsersPhoto)->for($user)->create();
        PhotoLike::factory()->for($anotherUsersPhoto)->for($otherUser)->create();
        PhotoLike::factory()->for($anotherUsersPhoto)->for($anotherOwner)->create();
        PhotoLike::factory()->for($ownedPhoto)->for($user)->create();
        PhotoLike::factory()->for($ownedPhoto)->for($otherUser)->create();

        $this->withToken($plainTextToken)
            ->getJson('/api/gallery')
            ->assertOk()
            ->assertJsonPath('photos.0.id', $anotherUsersPhoto->id)
            ->assertJsonPath('photos.0.like_count', 2)
            ->assertJsonPath('photos.0.liked_by_me', true)
            ->assertJsonPath('photos.0.is_mine', false)
            ->assertJsonPath('photos.0.can_like', true)
            ->assertJsonPath('photos.1.id', $ownedPhoto->id)
            ->assertJsonPath('photos.1.like_count', 1)
            ->assertJsonPath('photos.1.liked_by_me', false)
            ->assertJsonPath('photos.1.is_mine', true)
            ->assertJsonPath('photos.1.can_like', false);
    }

    /** @return array{User, string} */
    private function authenticatedUser(): array
    {
        $plainTextToken = 'valid-gallery-token';
        $user = User::factory()->create([
            'access_token_hash' => hash('sha256', $plainTextToken),
        ]);

        return [$user, $plainTextToken];
    }

    private function imageUrl(string $objectKey, string $process): string
    {
        $expires = 1789920000;
        $signature = base64_encode(hash_hmac(
            'sha1',
            "GET\n\n\n{$expires}\n/example-wedding-photos/{$objectKey}?x-oss-process={$process}",
            'test-access-key-secret',
            true,
        ));

        return 'https://images.example.test/'.$objectKey.'?'.http_build_query([
            'x-oss-process' => $process,
            'OSSAccessKeyId' => 'test-access-key-id',
            'Expires' => $expires,
            'Signature' => $signature,
        ], encoding_type: PHP_QUERY_RFC3986);
    }
}
