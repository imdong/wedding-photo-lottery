<?php

namespace Database\Factories;

use App\Enums\PhotoReviewStatus;
use App\Models\AdminReviewLog;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminReviewLog>
 */
class AdminReviewLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'photo_id' => Photo::factory(),
            'previous_status' => PhotoReviewStatus::Pending,
            'new_status' => fake()->randomElement([
                PhotoReviewStatus::Normal,
                PhotoReviewStatus::Good,
                PhotoReviewStatus::Rejected,
            ]),
        ];
    }
}
