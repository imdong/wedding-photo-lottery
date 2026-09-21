<?php

namespace App\Models;

use Database\Factories\DrawWinnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'draw_round_id',
    'user_id',
    'photo_id',
    'photo_weight',
    'position',
])]
class DrawWinner extends Model
{
    /** @use HasFactory<DrawWinnerFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public function drawRound(): BelongsTo
    {
        return $this->belongsTo(DrawRound::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'photo_weight' => 'decimal:6',
            'position' => 'integer',
        ];
    }
}
