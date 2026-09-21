<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\DrawRoundStatus;
use App\Models\DrawRound;
use App\Models\DrawWinner;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DrawWinnerControllerTest extends TestCase
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
            'oss.image_url_ttl_seconds' => 3600,
            'oss.upload_prefix' => 'wedding/photos',
        ]);
    }

    public function test_returns_an_empty_history_when_no_round_has_been_completed(): void
    {
        DrawRound::factory()->create();

        $this->getJson('/api/draw-winners')
            ->assertOk()
            ->assertExactJson(['rounds' => []]);
    }

    public function test_returns_completed_rounds_and_winners_in_display_order_without_login(): void
    {
        $this->travelTo('2026-09-21 13:00:00');
        $olderRound = DrawRound::factory()->create([
            'round_number' => 3,
            'draw_count' => 1,
            'status' => DrawRoundStatus::Completed,
            'completed_at' => '2026-09-21 11:00:00',
        ]);
        $newerRound = DrawRound::factory()->create([
            'round_number' => 8,
            'draw_count' => 2,
            'status' => DrawRoundStatus::Completed,
            'completed_at' => '2026-09-21 12:00:00',
        ]);
        $preparedRound = DrawRound::factory()->create([
            'round_number' => 9,
        ]);
        $olderUser = User::factory()->create(['phone' => '13800001111']);
        $firstUser = User::factory()->create(['phone' => '13800002222']);
        $secondUser = User::factory()->create(['phone' => '13800003333']);
        $preparedUser = User::factory()->create(['phone' => '13800004444']);
        $olderPhoto = Photo::factory()->for($olderUser)->create([
            'object_key' => 'wedding/photos/older.jpg',
        ]);
        $firstPhoto = Photo::factory()->for($firstUser)->create([
            'object_key' => 'wedding/photos/first.jpg',
        ]);
        $secondPhoto = Photo::factory()->for($secondUser)->create([
            'object_key' => 'wedding/photos/second.jpg',
        ]);
        $preparedPhoto = Photo::factory()->for($preparedUser)->create();
        DrawWinner::factory()->for($olderRound)->for($olderUser)->for($olderPhoto)->create([
            'position' => 1,
            'photo_weight' => 1.2,
        ]);
        DrawWinner::factory()->for($newerRound)->for($secondUser)->for($secondPhoto)->create([
            'position' => 2,
            'photo_weight' => 2.1,
        ]);
        DrawWinner::factory()->for($newerRound)->for($firstUser)->for($firstPhoto)->create([
            'position' => 1,
            'photo_weight' => 1.4,
        ]);
        DrawWinner::factory()->for($preparedRound)->for($preparedUser)->for($preparedPhoto)->create();

        $response = $this->getJson('/api/draw-winners')
            ->assertOk()
            ->assertJsonCount(2, 'rounds')
            ->assertJsonPath('rounds.0.round_id', $newerRound->id)
            ->assertJsonPath('rounds.0.round_number', 8)
            ->assertJsonPath('rounds.0.status', 'completed')
            ->assertJsonPath('rounds.0.completed_at', '2026-09-21T12:00:00.000000Z')
            ->assertJsonPath('rounds.0.winners.0.position', 1)
            ->assertJsonPath('rounds.0.winners.0.phone_suffix', '2222')
            ->assertJsonPath('rounds.0.winners.0.photo.id', $firstPhoto->id)
            ->assertJsonPath('rounds.0.winners.0.photo.weight', 1.4)
            ->assertJsonPath('rounds.0.winners.1.position', 2)
            ->assertJsonPath('rounds.0.winners.1.phone_suffix', '3333')
            ->assertJsonPath('rounds.0.winners.1.photo.id', $secondPhoto->id)
            ->assertJsonPath('rounds.1.round_id', $olderRound->id)
            ->assertJsonPath('rounds.1.round_number', 3)
            ->assertJsonPath('rounds.1.winners.0.phone_suffix', '1111')
            ->assertJsonMissing(['round_id' => $preparedRound->id]);

        $this->assertStringStartsWith(
            'https://images.example.test/wedding/photos/first.jpg?',
            $response->json('rounds.0.winners.0.photo.draw_url'),
        );
        $this->assertArrayNotHasKey('object_key', $response->json('rounds.0.winners.0.photo'));
        $this->assertArrayNotHasKey('phone', $response->json('rounds.0.winners.0'));
    }
}
