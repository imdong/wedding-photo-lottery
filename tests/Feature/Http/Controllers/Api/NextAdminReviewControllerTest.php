<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\AdminTokenService;
use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class NextAdminReviewControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.token_ttl_minutes' => 480,
            'oss.access_key_id' => 'test-access-key-id',
            'oss.access_key_secret' => 'test-access-key-secret',
            'oss.bucket' => 'example-wedding-photos',
            'oss.region' => 'cn-qingdao',
            'oss.endpoint' => 'https://oss-cn-qingdao.aliyuncs.com',
            'oss.internal_endpoint' => 'https://oss-cn-qingdao-internal.aliyuncs.com',
            'oss.bucket_url' => 'https://example-wedding-photos.oss-cn-qingdao.aliyuncs.com',
            'oss.public_url' => 'https://images.example.test',
            'oss.role_arn' => 'acs:ram::123456789:role/wedding-photo-upload',
            'oss.sts_endpoint' => 'https://sts.cn-qingdao.aliyuncs.com',
            'oss.temporary_credential_ttl_seconds' => 900,
            'oss.image_url_timezone' => 'Asia/Shanghai',
            'oss.upload_prefix' => 'wedding/photos',
        ]);
    }

    public function test_returns_401_when_admin_token_is_missing(): void
    {
        $this->getJson('/api/admin/reviews/next')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => '管理员身份凭证无效或已过期。',
                'code' => 'admin_unauthenticated',
            ]);
    }

    public function test_returns_clear_empty_state_when_no_photos_are_pending_review(): void
    {
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/reviews/next')
            ->assertOk()
            ->assertExactJson([
                'photo' => null,
                'remaining_count' => 0,
            ]);
    }

    public function test_returns_oldest_uploaded_pending_photo_and_remaining_count(): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        $user = User::factory()->create(['phone' => '13812345678']);
        $oldestPhoto = Photo::factory()->for($user)->create([
            'object_key' => 'wedding/photos/1/oldest.jpg',
            'original_filename' => 'oldest.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 2_457_600,
            'width' => 4032,
            'height' => 3024,
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Pending,
            'created_at' => '2026-09-20 07:00:00',
        ]);
        Photo::factory()->for($user)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Pending,
            'created_at' => '2026-09-20 07:00:00',
        ]);
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Pending,
            'created_at' => '2026-09-20 09:00:00',
        ]);
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Preparing,
            'review_status' => PhotoReviewStatus::Pending,
            'created_at' => '2026-09-20 06:00:00',
        ]);
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Failed,
            'review_status' => PhotoReviewStatus::Pending,
            'created_at' => '2026-09-20 05:00:00',
        ]);
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
            'created_at' => '2026-09-20 04:00:00',
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/reviews/next')
            ->assertOk()
            ->assertExactJson([
                'photo' => [
                    'id' => $oldestPhoto->id,
                    'original_filename' => 'oldest.jpg',
                    'mime_type' => 'image/jpeg',
                    'file_size' => 2_457_600,
                    'width' => 4032,
                    'height' => 3024,
                    'review_status' => 'pending',
                    'review_url' => $this->reviewUrl($oldestPhoto->object_key),
                    'uploader_phone_suffix' => '5678',
                    'created_at' => '2026-09-20T07:00:00.000000Z',
                ],
                'remaining_count' => 3,
            ]);
    }

    private function adminToken(): string
    {
        return app(AdminTokenService::class)->issue()['token'];
    }

    private function reviewUrl(string $objectKey): string
    {
        $process = 'image/resize,l_1600/quality,q_90/format,webp';
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
