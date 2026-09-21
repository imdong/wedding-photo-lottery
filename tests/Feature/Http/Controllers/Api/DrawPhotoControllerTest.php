<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DrawPhotoControllerTest extends TestCase
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

    public function test_publicly_returns_every_uploaded_approved_photo_without_pagination(): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        $normalPhotos = Photo::factory()->count(25)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
        ]);
        $goodPhoto = Photo::factory()->create([
            'object_key' => 'wedding/photos/good.jpg',
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Pending,
        ]);
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Rejected,
        ]);
        Photo::factory()->create([
            'upload_status' => PhotoUploadStatus::Preparing,
            'review_status' => PhotoReviewStatus::Good,
        ]);

        $response = $this->getJson('/api/draw-photos')
            ->assertOk()
            ->assertJsonCount(26, 'photos')
            ->assertJsonPath('photos.0.photo_id', $normalPhotos->first()->id)
            ->assertJsonPath('photos.25', [
                'photo_id' => $goodPhoto->id,
                'thumbnail_url' => $this->imageUrl(
                    $goodPhoto->object_key,
                    'image/resize,m_fill,w_64,h_64/quality,q_70/format,webp',
                ),
                'draw_url' => $this->imageUrl(
                    $goodPhoto->object_key,
                    'image/resize,l_1920/quality,q_92/format,webp',
                ),
            ]);

        $this->assertArrayNotHasKey('object_key', $response->json('photos.25'));
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
