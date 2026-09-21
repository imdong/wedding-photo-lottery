<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PreparedPhotoUploadControllerTest extends TestCase
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
            'public_url' => 'https://ali-cdn.example.test',
            'role_arn' => 'acs:ram::123456789:role/wedding-photo-upload',
            'sts_endpoint' => 'https://sts.cn-qingdao.aliyuncs.com',
            'temporary_credential_ttl_seconds' => 900,
            'upload_prefix' => 'wedding/photos',
        ]);
        config()->set('photos', [
            'max_batch_size' => 20,
            'max_size_bytes' => 30 * 1024 * 1024,
            'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic'],
        ]);
    }

    public function test_returns_401_when_token_is_missing(): void
    {
        $this->postJson('/api/uploads/prepare', [
            'photos' => [$this->validPhoto()],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');

        $this->assertDatabaseCount('photos', 0);
    }

    public function test_prepares_multiple_unique_photos_and_returns_one_credential_set(): void
    {
        Http::fake([
            'https://sts.cn-qingdao.aliyuncs.com' => Http::response($this->stsResponse()),
        ]);
        [$user, $plainTextToken] = $this->authenticatedUser();

        $response = $this->withToken($plainTextToken)
            ->postJson('/api/uploads/prepare', [
                'photos' => [
                    $this->validPhoto(),
                    $this->validPhoto([
                        'original_file_hash' => str_repeat('c', 64),
                        'upload_file_hash' => str_repeat('d', 64),
                        'filename' => 'second.png',
                        'mime_type' => 'image/png',
                    ]),
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('credentials.access_key_id', 'temporary-access-key-id')
            ->assertJsonPath('credentials.upload_url', 'https://ali-cdn.example.test')
            ->assertJsonMissingPath('credentials.bucket_url')
            ->assertJsonMissingPath('credentials.endpoint')
            ->assertJsonMissingPath('credentials.public_url')
            ->assertJsonPath('photos.0.index', 0)
            ->assertJsonPath('photos.0.status', 'ready')
            ->assertJsonPath('photos.0.original_file_hash', str_repeat('a', 64))
            ->assertJsonPath('photos.0.upload_file_hash', str_repeat('b', 64))
            ->assertJsonPath('photos.1.index', 1)
            ->assertJsonPath('photos.1.status', 'ready');

        $firstPhoto = Photo::query()->findOrFail($response->json('photos.0.photo_id'));
        $secondPhoto = Photo::query()->findOrFail($response->json('photos.1.photo_id'));

        $this->assertTrue($firstPhoto->user->is($user));
        $this->assertStringStartsWith("wedding/photos/{$user->id}/", $firstPhoto->object_key);
        $this->assertStringEndsWith('.jpg', $firstPhoto->object_key);
        $this->assertStringEndsWith('.png', $secondPhoto->object_key);
        $this->assertDatabaseCount('photos', 2);
        $this->assertDatabaseHas('photos', [
            'id' => $firstPhoto->id,
            'original_file_hash' => str_repeat('a', 64),
            'upload_file_hash' => str_repeat('b', 64),
            'original_filename' => 'first.jpg',
        ]);
        Http::assertSentCount(1);
    }

    public function test_marks_large_photos_with_an_original_upload_key(): void
    {
        Http::fake([
            'https://sts.cn-qingdao.aliyuncs.com' => Http::response($this->stsResponse()),
        ]);
        [, $plainTextToken] = $this->authenticatedUser();

        $response = $this->withToken($plainTextToken)
            ->postJson('/api/uploads/prepare', [
                'photos' => [
                    $this->validPhoto([
                        'file_size' => 21 * 1024 * 1024,
                        'filename' => 'large-photo.png',
                        'mime_type' => 'image/png',
                    ]),
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('photos.0.status', 'ready');

        $photo = Photo::query()->findOrFail($response->json('photos.0.photo_id'));

        $this->assertStringContainsString('.orig.', $photo->object_key);
        $this->assertSame($photo->object_key, $photo->original_object_key);
        $this->assertStringContainsString('.orig.', (string) $response->json('photos.0.object_key'));
    }

    public function test_skips_cross_field_hash_matches_uploaded_by_another_user(): void
    {
        Http::fake([
            'https://sts.cn-qingdao.aliyuncs.com' => Http::response($this->stsResponse()),
        ]);
        Photo::factory()->create([
            'original_file_hash' => str_repeat('a', 64),
            'upload_file_hash' => str_repeat('b', 64),
        ]);
        [, $plainTextToken] = $this->authenticatedUser();

        $response = $this->withToken($plainTextToken)
            ->postJson('/api/uploads/prepare', [
                'photos' => [
                    $this->validPhoto([
                        'original_file_hash' => str_repeat('b', 64),
                        'upload_file_hash' => str_repeat('c', 64),
                    ]),
                    $this->validPhoto([
                        'original_file_hash' => str_repeat('d', 64),
                        'upload_file_hash' => str_repeat('a', 64),
                    ]),
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('photos.0.status', 'duplicate')
            ->assertJsonPath('photos.0.duplicate_hashes', [str_repeat('b', 64)])
            ->assertJsonPath('photos.1.status', 'duplicate')
            ->assertJsonPath('photos.1.duplicate_hashes', [str_repeat('a', 64)]);

        $this->assertDatabaseCount('photos', 1);
    }

    public function test_skips_a_duplicate_later_in_the_same_batch(): void
    {
        Http::fake([
            'https://sts.cn-qingdao.aliyuncs.com' => Http::response($this->stsResponse()),
        ]);
        [, $plainTextToken] = $this->authenticatedUser();

        $response = $this->withToken($plainTextToken)
            ->postJson('/api/uploads/prepare', [
                'photos' => [
                    $this->validPhoto(),
                    $this->validPhoto([
                        'filename' => 'duplicate-copy.jpg',
                        'original_file_hash' => str_repeat('c', 64),
                        'upload_file_hash' => str_repeat('a', 64),
                    ]),
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('photos.0.status', 'ready')
            ->assertJsonPath('photos.1.status', 'duplicate')
            ->assertJsonPath('photos.1.duplicate_hashes', [str_repeat('a', 64)]);

        $this->assertDatabaseCount('photos', 1);
    }

    public function test_returns_422_for_invalid_photo_metadata_and_does_not_create_records(): void
    {
        [, $plainTextToken] = $this->authenticatedUser();

        $response = $this->withToken($plainTextToken)
            ->postJson('/api/uploads/prepare', [
                'photos' => [[
                    'original_file_hash' => 'invalid-hash',
                    'upload_file_hash' => str_repeat('b', 64),
                    'filename' => 'photo.gif',
                    'mime_type' => 'image/gif',
                    'file_size' => 31 * 1024 * 1024,
                    'unexpected' => true,
                ]],
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors([
                'photos.0',
                'photos.0.original_file_hash',
                'photos.0.mime_type',
                'photos.0.file_size',
            ])
            ->assertJsonFragment(['照片信息格式不正确。'])
            ->assertJsonFragment(['原始文件哈希格式不正确。'])
            ->assertJsonFragment(['不支持这种照片格式。'])
            ->assertJsonFragment(['单张照片不能超过 30 MB。']);

        $this->assertDatabaseCount('photos', 0);
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

    /** @param  array<string, mixed>  $overrides */
    private function validPhoto(array $overrides = []): array
    {
        return [
            'original_file_hash' => str_repeat('a', 64),
            'upload_file_hash' => str_repeat('b', 64),
            'filename' => 'first.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1_024_000,
            ...$overrides,
        ];
    }

    private function stsResponse(): array
    {
        return [
            'Credentials' => [
                'AccessKeyId' => 'temporary-access-key-id',
                'AccessKeySecret' => 'temporary-access-key-secret',
                'SecurityToken' => 'temporary-security-token',
                'Expiration' => '2026-09-20T08:15:00Z',
            ],
        ];
    }
}
