<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompletedPhotoUploadControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('oss', [
            'access_key_id' => 'test-access-key-id',
            'access_key_secret' => 'test-access-key-secret',
            'bucket' => 'example-wedding-photos',
            'region' => 'cn-qingdao',
            'endpoint' => 'https://oss-cn-qingdao.aliyuncs.com',
            'internal_endpoint' => 'https://oss-cn-qingdao-internal.aliyuncs.com',
            'bucket_url' => 'https://example-wedding-photos.oss-cn-qingdao.aliyuncs.com',
            'server_url' => 'https://example-wedding-photos.oss-cn-qingdao-internal.aliyuncs.com',
            'public_url' => 'https://cdn.example.com',
            'role_arn' => 'acs:ram::123456789:role/wedding-photo-upload',
            'sts_endpoint' => 'https://sts.cn-qingdao.aliyuncs.com',
            'temporary_credential_ttl_seconds' => 900,
            'upload_prefix' => 'wedding/photos',
        ]);
    }

    public function test_returns_401_when_token_is_missing(): void
    {
        $photo = Photo::factory()->create();

        $this->postJson("/api/uploads/{$photo->id}/complete")
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_returns_404_for_a_photo_owned_by_another_user(): void
    {
        $photo = Photo::factory()->create();
        [, $plainTextToken] = $this->authenticatedUser();

        $this->withToken($plainTextToken)
            ->postJson("/api/uploads/{$photo->id}/complete")
            ->assertNotFound();

        $this->assertSame(PhotoUploadStatus::Preparing, $photo->refresh()->upload_status);
        Http::assertNothingSent();
    }

    public function test_returns_409_when_the_object_does_not_exist(): void
    {
        Http::fake([
            'https://example-wedding-photos.oss-cn-qingdao-internal.aliyuncs.com/*' => Http::response('', 404),
        ]);
        [$user, $plainTextToken] = $this->authenticatedUser();
        $photo = Photo::factory()->for($user)->create();

        $this->withToken($plainTextToken)
            ->postJson("/api/uploads/{$photo->id}/complete")
            ->assertConflict()
            ->assertExactJson([
                'message' => '照片尚未上传完成，请重试。',
                'code' => 'object_not_found',
            ]);

        $this->assertSame(PhotoUploadStatus::Preparing, $photo->refresh()->upload_status);
        Http::assertSentCount(1);
    }

    public function test_confirms_the_object_and_marks_the_photo_uploaded(): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        Http::fake([
            'https://example-wedding-photos.oss-cn-qingdao-internal.aliyuncs.com/*' => Http::response('', 200),
        ]);
        [$user, $plainTextToken] = $this->authenticatedUser();
        $photo = Photo::factory()->for($user)->create([
            'object_key' => 'wedding/photos/42/my photo.jpg',
        ]);

        $this->withToken($plainTextToken)
            ->postJson("/api/uploads/{$photo->id}/complete")
            ->assertOk()
            ->assertExactJson([
                'id' => $photo->id,
                'upload_status' => 'uploaded',
                'review_status' => 'pending',
            ]);

        $photo->refresh();
        $this->assertSame(PhotoUploadStatus::Uploaded, $photo->upload_status);
        $this->assertSame(PhotoReviewStatus::Pending, $photo->review_status);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'HEAD'
                && $request->url() === 'https://example-wedding-photos.oss-cn-qingdao-internal.aliyuncs.com/wedding/photos/42/my%20photo.jpg'
                && $request->hasHeader('Date', 'Sun, 20 Sep 2026 08:00:00 GMT')
                && str_starts_with($request->header('Authorization')[0], 'OSS test-access-key-id:');
        });
    }

    public function test_processes_large_photos_into_a_displayable_object_before_marking_uploaded(): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        config(['photos.large_original_size_bytes' => 1]);

        Http::fake([
            'https://example-wedding-photos.oss-cn-qingdao-internal.aliyuncs.com/*OSSAccessKeyId=*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAACCAIAAADwyuo0AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAEElEQVQImWP8z4AATEhsBgAZNQED985GgAAAAABJRU5ErkJggg=='), 200, [
                        'Content-Type' => 'image/png',
                    ]);
                }

                return Http::response('', 200);
            },
            'https://example-wedding-photos.oss-cn-qingdao-internal.aliyuncs.com/*' => Http::response('', 200),
        ]);
        [$user, $plainTextToken] = $this->authenticatedUser();
        $photo = Photo::factory()->for($user)->create([
            'object_key' => 'wedding/photos/42/my photo.orig.png',
            'original_object_key' => 'wedding/photos/42/my photo.orig.png',
            'mime_type' => 'image/png',
            'file_size' => 2,
        ]);

        $this->withToken($plainTextToken)
            ->postJson("/api/uploads/{$photo->id}/complete")
            ->assertOk()
            ->assertExactJson([
                'id' => $photo->id,
                'upload_status' => 'uploaded',
                'review_status' => 'pending',
            ]);

        $photo->refresh();
        $this->assertSame(PhotoUploadStatus::Uploaded, $photo->upload_status);
        $this->assertSame(PhotoReviewStatus::Pending, $photo->review_status);
        $this->assertSame('wedding/photos/42/my photo.orig.png', $photo->original_object_key);
        $this->assertSame('wedding/photos/42/my photo.webp', $photo->object_key);
        Http::assertSentCount(3);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $imageSize = getimagesizefromstring($request->body());

            return is_array($imageSize)
                && $imageSize[0] === $imageSize[1] * 2
                && $request->url() === 'https://example-wedding-photos.oss-cn-qingdao-internal.aliyuncs.com/wedding/photos/42/my%20photo.webp';
        });
    }

    public function test_returns_an_already_uploaded_photo_without_checking_oss_again(): void
    {
        [$user, $plainTextToken] = $this->authenticatedUser();
        $photo = Photo::factory()->for($user)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
        ]);

        $this->withToken($plainTextToken)
            ->postJson("/api/uploads/{$photo->id}/complete")
            ->assertOk()
            ->assertJsonPath('upload_status', 'uploaded');

        Http::assertNothingSent();
    }

    /** @return array{User, string} */
    private function authenticatedUser(): array
    {
        $plainTextToken = 'valid-upload-token';
        $user = User::factory()->create([
            'access_token_hash' => hash('sha256', $plainTextToken),
        ]);

        return [$user, $plainTextToken];
    }
}
