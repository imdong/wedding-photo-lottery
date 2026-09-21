<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\DrawRoundStatus;
use App\LotteryRandomSource;
use App\Models\DrawRound;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SequenceLotteryRandomSource;
use Tests\TestCase;

class DrawRoundControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
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

    public function test_returns_404_when_round_does_not_exist(): void
    {
        $this->postJson('/api/draw-rounds/999/draw')->assertNotFound();
    }

    public function test_draws_persists_and_idempotently_returns_results_without_login(): void
    {
        $this->travelTo('2026-09-21 12:00:00');
        $firstUser = User::factory()->create(['phone' => '13812345678']);
        $secondUser = User::factory()->create(['phone' => '13987654321']);
        $firstPhoto = Photo::factory()->for($firstUser)->create([
            'object_key' => 'wedding/photos/first.jpg',
        ]);
        $secondPhoto = Photo::factory()->for($secondUser)->create([
            'object_key' => 'wedding/photos/second.jpg',
        ]);
        $selectedSecondPhoto = Photo::factory()->for($secondUser)->create([
            'object_key' => 'wedding/photos/selected-second.jpg',
        ]);
        $round = DrawRound::factory()->create([
            'round_number' => 7,
            'draw_count' => 2,
            'candidate_snapshot' => [
                'users' => [
                    $this->candidateUser($firstUser, [
                        $this->candidatePhoto($firstPhoto, 1.0),
                    ]),
                    $this->candidateUser($secondUser, [
                        $this->candidatePhoto($secondPhoto, 1.0),
                        $this->candidatePhoto($selectedSecondPhoto, 1.0),
                    ]),
                ],
                'total_weight' => 3.0,
            ],
            'settings_snapshot' => [
                'draw_count' => 2,
                'selection_mode' => 'user_then_photo',
            ],
        ]);
        $this->app->instance(
            LotteryRandomSource::class,
            new SequenceLotteryRandomSource([0.1, 0.1, 0.5, 0.8]),
        );

        $firstResponse = $this->postJson("/api/draw-rounds/{$round->id}/draw")
            ->assertOk()
            ->assertJsonPath('round_id', $round->id)
            ->assertJsonPath('round_number', 7)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('completed_at', '2026-09-21T12:00:00.000000Z')
            ->assertJsonPath('winners.0.position', 1)
            ->assertJsonPath('winners.0.user_id', $firstUser->id)
            ->assertJsonPath('winners.0.phone_suffix', '5678')
            ->assertJsonPath('winners.0.photo.id', $firstPhoto->id)
            ->assertJsonPath('winners.0.photo.weight', 1)
            ->assertJsonPath('winners.1.position', 2)
            ->assertJsonPath('winners.1.user_id', $secondUser->id)
            ->assertJsonPath('winners.1.phone_suffix', '4321')
            ->assertJsonPath('winners.1.photo.id', $selectedSecondPhoto->id)
            ->assertJsonPath('winners.1.photo.weight', 1);

        $this->assertStringStartsWith(
            'https://images.example.test/wedding/photos/first.jpg?',
            $firstResponse->json('winners.0.photo.draw_url'),
        );

        $secondResponse = $this->postJson("/api/draw-rounds/{$round->id}/draw")
            ->assertOk();

        $this->assertSame($firstResponse->json(), $secondResponse->json());
        $this->assertDatabaseHas('draw_rounds', [
            'id' => $round->id,
            'status' => DrawRoundStatus::Completed->value,
            'completed_at' => '2026-09-21 12:00:00',
        ]);
        $this->assertDatabaseCount('draw_winners', 2);
        $this->assertDatabaseHas('draw_winners', [
            'draw_round_id' => $round->id,
            'user_id' => $firstUser->id,
            'photo_id' => $firstPhoto->id,
            'position' => 1,
        ]);
        $this->assertDatabaseHas('draw_winners', [
            'draw_round_id' => $round->id,
            'user_id' => $secondUser->id,
            'photo_id' => $selectedSecondPhoto->id,
            'position' => 2,
        ]);
    }

    public function test_returns_409_when_round_is_cancelled(): void
    {
        $round = DrawRound::factory()->create([
            'status' => DrawRoundStatus::Cancelled,
        ]);

        $this->postJson("/api/draw-rounds/{$round->id}/draw")
            ->assertConflict()
            ->assertExactJson([
                'message' => '当前轮次不能执行抽奖。',
                'code' => 'draw_round_not_prepared',
            ]);

        $this->assertDatabaseCount('draw_winners', 0);
    }

    public function test_rejects_and_rolls_back_a_snapshot_with_a_photo_owned_by_another_user(): void
    {
        $snapshotUser = User::factory()->create();
        $photoOwner = User::factory()->create();
        $foreignPhoto = Photo::factory()->for($photoOwner)->create();
        $round = DrawRound::factory()->create([
            'candidate_snapshot' => [
                'users' => [
                    $this->candidateUser($snapshotUser, [
                        $this->candidatePhoto($foreignPhoto, 1.0),
                    ]),
                ],
                'total_weight' => 1.0,
            ],
        ]);
        $this->app->instance(
            LotteryRandomSource::class,
            new SequenceLotteryRandomSource([0.5, 0.5]),
        );

        $this->postJson("/api/draw-rounds/{$round->id}/draw")
            ->assertConflict()
            ->assertExactJson([
                'message' => '当前抽奖数据无效，请重新准备抽奖。',
                'code' => 'invalid_draw_snapshot',
            ]);

        $this->assertDatabaseCount('draw_winners', 0);
        $this->assertDatabaseHas('draw_rounds', [
            'id' => $round->id,
            'status' => DrawRoundStatus::Prepared->value,
            'completed_at' => null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $photos
     * @return array<string, mixed>
     */
    private function candidateUser(User $user, array $photos): array
    {
        return [
            'user_id' => $user->id,
            'phone_suffix' => substr($user->phone, -4),
            'weight' => array_sum(array_column($photos, 'weight')),
            'photos' => $photos,
        ];
    }

    /** @return array<string, mixed> */
    private function candidatePhoto(Photo $photo, float $weight): array
    {
        return [
            'photo_id' => $photo->id,
            'object_key' => $photo->object_key,
            'lottery_status' => 'approved',
            'effective_likes' => 0.0,
            'like_bonus' => 0.0,
            'weight' => $weight,
        ];
    }
}
