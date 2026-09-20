<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['phone', 'invite_code', 'invited_by_user_id'])]
#[Hidden(['access_token_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by_user_id');
    }

    public function invitees(): HasMany
    {
        return $this->hasMany(self::class, 'invited_by_user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    /** @return HasMany<PhotoLike, $this> */
    public function photoLikes(): HasMany
    {
        return $this->hasMany(PhotoLike::class);
    }
}
