<?php

namespace Tests\Feature;

use App\Enums\DrawRoundStatus;
use App\Models\DrawRound;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\AssertsJsonValues;
use Tests\TestCase;

class DrawRoundTest extends TestCase
{
    use AssertsJsonValues;
    use LazilyRefreshDatabase;

    public function test_creates_and_serializes_an_empty_prepared_round(): void
    {
        $drawRound = DrawRound::query()->create([
            'round_number' => 1,
            'draw_count' => 3,
        ])->refresh();

        $serializedRound = json_decode(
            $drawRound->toJson(),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertModelExists($drawRound);
        $this->assertSame(1, $drawRound->round_number);
        $this->assertSame(3, $drawRound->draw_count);
        $this->assertSame(DrawRoundStatus::Prepared, $drawRound->status);
        $this->assertSame([], $drawRound->candidate_snapshot);
        $this->assertSame([], $drawRound->settings_snapshot);
        $this->assertNull($drawRound->completed_at);
        $this->assertSame('prepared', $serializedRound['status']);
        $this->assertSame([], $serializedRound['candidate_snapshot']);
        $this->assertSame([], $serializedRound['settings_snapshot']);
    }

    public function test_preserves_candidate_and_setting_snapshots_as_json(): void
    {
        $drawRound = DrawRound::factory()->create([
            'candidate_snapshot' => [
                'users' => [
                    ['user_id' => 12, 'weight' => 2.8],
                ],
                'total_weight' => 2.8,
            ],
            'settings_snapshot' => [
                'selection_mode' => 'user_then_photo',
                'draw_count' => 1,
            ],
        ]);

        $persistedRound = DrawRound::query()->findOrFail($drawRound->id);

        $this->assertJsonValueSame([
            'users' => [
                ['user_id' => 12, 'weight' => 2.8],
            ],
            'total_weight' => 2.8,
        ], $persistedRound->candidate_snapshot);
        $this->assertJsonValueSame([
            'selection_mode' => 'user_then_photo',
            'draw_count' => 1,
        ], $persistedRound->settings_snapshot);
    }
}
