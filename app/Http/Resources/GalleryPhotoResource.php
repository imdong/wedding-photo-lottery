<?php

namespace App\Http\Resources;

use App\Models\Photo;
use App\OssImageUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Photo */
class GalleryPhotoResource extends JsonResource
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
            'id' => $this->id,
            'thumbnail_url' => $this->imageUrlGenerator->thumbnail($this->object_key),
            'preview_url' => $this->imageUrlGenerator->gallery($this->object_key),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
