<?php

namespace Tests\Feature\Models;

use App\Models\DrawRound;
use App\Models\DrawWinner;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DrawWinnerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_records_a_winner_with_its_round_user_photo_weight_and_position(): void
    {
        $drawRound = DrawRound::factory()->create();
        $user = User::factory()->create();
        $photo = Photo::factory()->for($user)->create();

        $winner = DrawWinner::factory()
            ->for($drawRound)
            ->for($photo)
            ->create([
                'user_id' => $user->id,
                'photo_weight' => 1.8,
                'position' => 1,
            ]);

        $this->assertModelExists($winner);
        $this->assertTrue($drawRound->is($winner->drawRound));
        $this->assertTrue($user->is($winner->user));
        $this->assertTrue($photo->is($winner->photo));
        $this->assertSame('1.800000', $winner->photo_weight);
        $this->assertSame(1, $winner->position);
    }

    public function test_rejects_the_same_user_twice_in_one_round(): void
    {
        $drawRound = DrawRound::factory()->create();
        $user = User::factory()->create();
        $firstPhoto = Photo::factory()->for($user)->create();
        $secondPhoto = Photo::factory()->for($user)->create();
        DrawWinner::factory()->for($drawRound)->for($firstPhoto)->create([
            'user_id' => $user->id,
            'position' => 1,
        ]);

        $this->expectException(QueryException::class);

        DrawWinner::factory()->for($drawRound)->for($secondPhoto)->create([
            'user_id' => $user->id,
            'position' => 2,
        ]);
    }

    public function test_rejects_the_same_position_twice_in_one_round(): void
    {
        $drawRound = DrawRound::factory()->create();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstPhoto = Photo::factory()->for($firstUser)->create();
        $secondPhoto = Photo::factory()->for($secondUser)->create();
        DrawWinner::factory()->for($drawRound)->for($firstPhoto)->create([
            'user_id' => $firstUser->id,
            'position' => 1,
        ]);

        $this->expectException(QueryException::class);

        DrawWinner::factory()->for($drawRound)->for($secondPhoto)->create([
            'user_id' => $secondUser->id,
            'position' => 1,
        ]);
    }
}
