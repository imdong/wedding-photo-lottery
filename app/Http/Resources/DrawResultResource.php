<?php

namespace App\Http\Resources;

use App\Models\DrawRound;
use App\Models\DrawWinner;
use App\OssImageUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DrawRound */
class DrawResultResource extends JsonResource
{
    public function __construct(mixed $resource, private readonly OssImageUrlGenerator $imageUrlGenerator)
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'round_id' => $this->id,
            'round_number' => $this->round_number,
            'status' => $this->status->value,
            'completed_at' => $this->completed_at?->toISOString(),
            'winners' => $this->winners->map(fn (DrawWinner $winner): array => [
                'position' => $winner->position,
                'user_id' => $winner->user_id,
                'phone_suffix' => substr($winner->user->phone, -4),
                'photo' => [
                    'id' => $winner->photo_id,
                    'draw_url' => $this->imageUrlGenerator->draw($winner->photo->object_key),
                    'weight' => (float) $winner->photo_weight,
                ],
            ])->all(),
        ];
    }
}
