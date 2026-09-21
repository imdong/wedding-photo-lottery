<?php

namespace App\Http\Resources;

use App\Models\Photo;
use App\OssImageUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Photo */
class DrawPhotoResource extends JsonResource
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
            'photo_id' => $this->id,
            'thumbnail_url' => $this->imageUrlGenerator->cloud($this->object_key),
            'draw_url' => $this->imageUrlGenerator->draw($this->object_key),
        ];
    }
}
