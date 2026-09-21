<?php

namespace Database\Factories;

use App\Enums\DrawRoundStatus;
use App\Models\DrawRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrawRound>
 */
class DrawRoundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'round_number' => fake()->unique()->numberBetween(1, 1_000_000),
            'draw_count' => 1,
            'status' => DrawRoundStatus::Prepared,
            'candidate_snapshot' => [],
            'settings_snapshot' => [],
            'completed_at' => null,
        ];
    }
}
