<?php

namespace App\Models;

use Database\Factories\PhotoLikeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['photo_id', 'user_id'])]
class PhotoLike extends Model
{
    /** @use HasFactory<PhotoLikeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
