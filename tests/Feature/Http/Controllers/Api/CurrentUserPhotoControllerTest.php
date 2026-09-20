<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CurrentUserPhotoControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_401_when_token_is_missing(): void
    {
        $this->getJson('/api/photos/mine')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => '身份凭证无效，请重新输入手机号。',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_returns_an_empty_list_when_the_user_has_no_photos(): void
    {
        [, $plainTextToken] = $this->authenticatedUser();

        $this->withToken($plainTextToken)
            ->getJson('/api/photos/mine')
            ->assertOk()
            ->assertExactJson(['photos' => []]);
    }

    public function test_returns_only_the_current_users_photos_in_latest_first_order(): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        [$user, $plainTextToken] = $this->authenticatedUser();
        $olderPhoto = Photo::factory()->for($user)->create([
            'original_filename' => 'older.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1000,
            'upload_status' => PhotoUploadStatus::Preparing,
            'review_status' => PhotoReviewStatus::Pending,
            'created_at' => '2026-09-20 07:00:00',
        ]);
        $newerPhoto = Photo::factory()->for($user)->create([
            'original_filename' => 'newer.webp',
            'mime_type' => 'image/webp',
            'file_size' => 2000,
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
            'created_at' => '2026-09-20 08:00:00',
        ]);
        Photo::factory()->create([
            'original_filename' => 'another-users-pending-photo.jpg',
            'review_status' => PhotoReviewStatus::Pending,
            'created_at' => '2026-09-20 09:00:00',
        ]);

        $this->withToken($plainTextToken)
            ->getJson('/api/photos/mine')
            ->assertOk()
            ->assertExactJson([
                'photos' => [
                    [
                        'id' => $newerPhoto->id,
                        'original_filename' => 'newer.webp',
                        'mime_type' => 'image/webp',
                        'file_size' => 2000,
                        'upload_status' => 'uploaded',
                        'review_status' => 'good',
                        'thumbnail_url' => $this->thumbnailUrl($newerPhoto->object_key),
                        'created_at' => '2026-09-20T08:00:00.000000Z',
                    ],
                    [
                        'id' => $olderPhoto->id,
                        'original_filename' => 'older.jpg',
                        'mime_type' => 'image/jpeg',
                        'file_size' => 1000,
                        'upload_status' => 'preparing',
                        'review_status' => 'pending',
                        'created_at' => '2026-09-20T07:00:00.000000Z',
                    ],
                ],
            ]);
    }

    /** @return array{User, string} */
    private function authenticatedUser(): array
    {
        $plainTextToken = 'valid-photo-list-token';
        $user = User::factory()->create([
            'access_token_hash' => hash('sha256', $plainTextToken),
        ]);

        return [$user, $plainTextToken];
    }

    private function thumbnailUrl(string $objectKey): string
    {
        $process = 'image/resize,m_fill,w_400,h_400/quality,q_85/format,webp';
        $expires = 1789894800;
        $bucket = (string) config('oss.bucket');
        $signature = base64_encode(hash_hmac(
            'sha1',
            "GET\n\n\n{$expires}\n/{$bucket}/{$objectKey}?x-oss-process={$process}",
            (string) config('oss.access_key_secret'),
            true,
        ));

        return rtrim((string) config('oss.public_url'), '/').'/'.$objectKey.'?'.http_build_query([
            'x-oss-process' => $process,
            'OSSAccessKeyId' => config('oss.access_key_id'),
            'Expires' => $expires,
            'Signature' => $signature,
        ], encoding_type: PHP_QUERY_RFC3986);
    }
}
