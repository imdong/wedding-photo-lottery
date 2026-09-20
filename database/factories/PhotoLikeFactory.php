<?php

namespace Database\Factories;

use App\Models\Photo;
use App\Models\PhotoLike;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhotoLike>
 */
class PhotoLikeFactory extends Factory
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
            'user_id' => User::factory(),
        ];
    }
}
