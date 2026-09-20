<?php

namespace Tests\Feature\Models;

use App\Models\Photo;
use App\Models\PhotoLike;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PhotoLikeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_photo_like_belongs_to_photo_and_user(): void
    {
        $photo = Photo::factory()->create();
        $user = User::factory()->create();
        $photoLike = PhotoLike::factory()
            ->for($photo)
            ->for($user)
            ->create();

        $this->assertModelExists($photoLike);
        $this->assertTrue($photoLike->photo->is($photo));
        $this->assertTrue($photoLike->user->is($user));
        $this->assertTrue($photo->likes()->whereKey($photoLike)->exists());
        $this->assertTrue($user->photoLikes()->whereKey($photoLike)->exists());
        $this->assertNotNull($photoLike->created_at);
    }

    public function test_duplicate_photo_and_user_pair_is_rejected(): void
    {
        $photo = Photo::factory()->create();
        $user = User::factory()->create();
        PhotoLike::factory()->for($photo)->for($user)->create();

        $this->expectException(QueryException::class);

        PhotoLike::factory()->for($photo)->for($user)->create();
    }

    public function test_deleting_photo_deletes_its_likes(): void
    {
        $photo = Photo::factory()->create();
        $photoLike = PhotoLike::factory()->for($photo)->create();

        $photo->delete();

        $this->assertModelMissing($photoLike);
    }

    public function test_deleting_user_deletes_their_likes(): void
    {
        $user = User::factory()->create();
        $photoLike = PhotoLike::factory()->for($user)->create();

        $user->delete();

        $this->assertModelMissing($photoLike);
    }
}
