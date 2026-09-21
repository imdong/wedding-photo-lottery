<?php

namespace Database\Factories;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $objectKey = 'photos/'.Str::uuid().'.jpg';

        return [
            'user_id' => User::factory(),
            'object_key' => $objectKey,
            'original_object_key' => $objectKey,
            'original_filename' => fake()->lexify('IMG_????').'.jpg',
            'original_file_hash' => hash('sha256', 'original-'.Str::random(64)),
            'upload_file_hash' => hash('sha256', 'upload-'.Str::random(64)),
            'mime_type' => 'image/jpeg',
            'file_size' => fake()->numberBetween(100_000, 20_000_000),
            'width' => null,
            'height' => null,
            'upload_status' => PhotoUploadStatus::Preparing,
            'review_status' => PhotoReviewStatus::Pending,
            'reviewed_at' => null,
            'exclude_from_lottery' => false,
        ];
    }
}
