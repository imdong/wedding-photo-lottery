<?php

namespace App\Models;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'object_key',
    'original_filename',
    'file_hash',
    'mime_type',
    'file_size',
    'width',
    'height',
    'upload_status',
    'review_status',
    'reviewed_at',
])]
class Photo extends Model
{
    /** @use HasFactory<PhotoFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'upload_status' => PhotoUploadStatus::Preparing->value,
        'review_status' => PhotoReviewStatus::Pending->value,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'upload_status' => PhotoUploadStatus::class,
            'review_status' => PhotoReviewStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }
}
