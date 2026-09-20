<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\Photo;
use App\Models\PhotoLike;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PhotoUnlikeControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_401_when_token_is_missing(): void
    {
        $photo = Photo::factory()->create();

        $this->deleteJson("/api/photos/{$photo->id}/like")
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_returns_404_when_photo_does_not_exist(): void
    {
        [, $plainTextToken] = $this->authenticatedUser();

        $this->withToken($plainTextToken)
            ->deleteJson('/api/photos/999/like')
            ->assertNotFound();
    }

    public function test_removes_only_current_users_like_idempotently(): void
    {
        [$user, $plainTextToken] = $this->authenticatedUser();
        $photo = Photo::factory()->create();
        $currentUsersLike = PhotoLike::factory()
            ->for($photo)
            ->for($user)
            ->create();
        $anotherUsersLike = PhotoLike::factory()
            ->for($photo)
            ->create();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withToken($plainTextToken)
                ->deleteJson("/api/photos/{$photo->id}/like")
                ->assertOk()
                ->assertExactJson([
                    'photo_id' => $photo->id,
                    'liked' => false,
                ]);
        }

        $this->assertModelMissing($currentUsersLike);
        $this->assertModelExists($anotherUsersLike);
        $this->assertDatabaseCount('photo_likes', 1);
    }

    public function test_succeeds_when_current_user_has_not_liked_photo(): void
    {
        [, $plainTextToken] = $this->authenticatedUser();
        $photo = Photo::factory()->create();

        $this->withToken($plainTextToken)
            ->deleteJson("/api/photos/{$photo->id}/like")
            ->assertOk()
            ->assertExactJson([
                'photo_id' => $photo->id,
                'liked' => false,
            ]);

        $this->assertDatabaseCount('photo_likes', 0);
    }

    /** @return array{User, string} */
    private function authenticatedUser(): array
    {
        $plainTextToken = 'valid-photo-unlike-token';
        $user = User::factory()->create([
            'access_token_hash' => hash('sha256', $plainTextToken),
        ]);

        return [$user, $plainTextToken];
    }
}
