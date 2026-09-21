<?php

namespace App\Http\Resources;

use App\Models\DrawRound;
use App\OssImageUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DrawRound */
class DrawRoundResource extends JsonResource
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
        $snapshot = $this->candidate_snapshot;
        $snapshot['users'] = collect($snapshot['users'])
            ->map(function (array $user): array {
                $user['photos'] = collect($user['photos'])
                    ->map(function (array $photo): array {
                        $objectKey = $photo['object_key'];
                        unset($photo['object_key']);

                        return [
                            'photo_id' => $photo['photo_id'],
                            'thumbnail_url' => $this->imageUrlGenerator->thumbnail($objectKey),
                            'draw_url' => $this->imageUrlGenerator->draw($objectKey),
                            ...collect($photo)->except('photo_id')->all(),
                        ];
                    })
                    ->all();

                return $user;
            })
            ->all();

        return [
            'id' => $this->id,
            'round_number' => $this->round_number,
            'draw_count' => $this->draw_count,
            'status' => $this->status->value,
            'candidate_snapshot' => $snapshot,
            'settings_snapshot' => $this->settings_snapshot,
            'created_at' => $this->created_at->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
