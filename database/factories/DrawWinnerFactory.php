<?php

namespace Database\Factories;

use App\Models\DrawRound;
use App\Models\DrawWinner;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrawWinner>
 */
class DrawWinnerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'draw_round_id' => DrawRound::factory(),
            'photo_id' => Photo::factory(),
            'user_id' => fn (array $attributes): int => Photo::query()
                ->findOrFail($attributes['photo_id'])
                ->user_id,
            'photo_weight' => fake()->randomFloat(6, 1, 2.4),
            'position' => 1,
        ];
    }
}
