<?php

namespace App\Models;

use App\Enums\PhotoReviewStatus;
use Database\Factories\AdminReviewLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'photo_id',
    'previous_status',
    'new_status',
])]
class AdminReviewLog extends Model
{
    /** @use HasFactory<AdminReviewLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'previous_status' => PhotoReviewStatus::class,
            'new_status' => PhotoReviewStatus::class,
        ];
    }
}
