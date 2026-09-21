<?php

namespace App\Models;

use App\Enums\DrawRoundStatus;
use Database\Factories\DrawRoundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'round_number',
    'draw_count',
    'status',
    'candidate_snapshot',
    'settings_snapshot',
    'completed_at',
])]
class DrawRound extends Model
{
    /** @use HasFactory<DrawRoundFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => DrawRoundStatus::Prepared->value,
        'candidate_snapshot' => '[]',
        'settings_snapshot' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'draw_count' => 'integer',
            'status' => DrawRoundStatus::class,
            'candidate_snapshot' => 'array',
            'settings_snapshot' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
