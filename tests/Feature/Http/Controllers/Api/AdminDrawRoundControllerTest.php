<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\AdminTokenService;
use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\DrawRound;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminDrawRoundControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.token_ttl_minutes' => 480,
            'lottery.exclude_previous_winners' => false,
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
            'oss.image_url_ttl_seconds' => 3600,
            'oss.upload_prefix' => 'wedding/photos',
        ]);
    }

    public function test_returns_401_when_admin_token_is_missing(): void
    {
        $this->postJson('/api/admin/draw-rounds')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'admin_unauthenticated');

        $this->assertDatabaseCount('draw_rounds', 0);
    }

    public function test_returns_409_without_creating_a_round_when_candidate_users_are_insufficient(): void
    {
        config(['lottery.draw_count' => 2]);
        $user = User::factory()->create();
        Photo::factory()->count(2)->for($user)->create([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/draw-rounds')
            ->assertConflict()
            ->assertExactJson([
                'message' => '可参与抽奖的人数不足，请先完成照片审核。',
                'code' => 'insufficient_draw_candidates',
                'required_count' => 2,
                'available_count' => 1,
            ]);

        $this->assertDatabaseCount('draw_rounds', 0);
    }

    public function test_creates_and_returns_a_frozen_round_using_server_configuration(): void
    {
        $this->travelTo('2026-09-21 10:00:00');
        config(['lottery.draw_count' => 2]);
        $normalUser = User::factory()->create(['phone' => '13812345678']);
        $featuredUser = User::factory()->create(['phone' => '13987654321']);
        $normalPhoto = Photo::factory()->for($normalUser)->create([
            'object_key' => 'wedding/photos/normal.jpg',
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Normal,
        ]);
        $featuredPhoto = Photo::factory()->for($featuredUser)->create([
            'object_key' => 'wedding/photos/featured.jpg',
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Good,
        ]);

        $response = $this->withToken($this->adminToken())
            ->postJson('/api/admin/draw-rounds', ['draw_count' => 99])
            ->assertCreated()
            ->assertJsonPath('round_number', 1)
            ->assertJsonPath('draw_count', 2)
            ->assertJsonPath('status', 'prepared')
            ->assertJsonPath('candidate_snapshot.total_weight', 2.8)
            ->assertJsonPath('candidate_snapshot.users.0.phone_suffix', '5678')
            ->assertJsonPath('candidate_snapshot.users.0.photos.0.thumbnail_url', $this->thumbnailUrl($normalPhoto->object_key))
            ->assertJsonPath('candidate_snapshot.users.0.photos.0.draw_url', $this->drawUrl($normalPhoto->object_key))
            ->assertJsonPath('candidate_snapshot.users.1.phone_suffix', '4321')
            ->assertJsonPath('candidate_snapshot.users.1.photos.0.draw_url', $this->drawUrl($featuredPhoto->object_key))
            ->assertJsonPath('settings_snapshot.draw_count', 2)
            ->assertJsonPath('settings_snapshot.selection_mode', 'user_then_photo')
            ->assertJsonPath('created_at', '2026-09-21T10:00:00.000000Z')
            ->assertJsonPath('completed_at', null);

        $this->assertArrayNotHasKey(
            'object_key',
            $response->json('candidate_snapshot.users.0.photos.0'),
        );
        $drawRound = DrawRound::query()->sole();
        $this->assertSame(2, $drawRound->draw_count);
        $this->assertSame(
            $normalPhoto->object_key,
            $drawRound->candidate_snapshot['users'][0]['photos'][0]['object_key'],
        );
    }

    private function adminToken(): string
    {
        return app(AdminTokenService::class)->issue()['token'];
    }

    private function drawUrl(string $objectKey): string
    {
        return $this->imageUrl($objectKey, 'image/resize,l_1920/quality,q_92/format,webp');
    }

    private function thumbnailUrl(string $objectKey): string
    {
        return $this->imageUrl($objectKey, 'image/resize,m_fill,w_400,h_400/quality,q_85/format,webp');
    }

    private function imageUrl(string $objectKey, string $process): string
    {
        $expires = 1789988400;
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
