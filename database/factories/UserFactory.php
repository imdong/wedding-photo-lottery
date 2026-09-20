<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => fake()->unique()->numerify('1##########'),
            'access_token_hash' => hash('sha256', Str::random(64)),
            'invite_code' => fake()->unique()->regexify('[A-Za-z0-9]{8}'),
            'invited_by_user_id' => null,
        ];
    }
}
