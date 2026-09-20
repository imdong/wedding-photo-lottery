<?php

namespace App\Http\Resources;

use App\Models\Photo;
use App\OssImageUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin Photo */
class AdminReviewPhotoResource extends JsonResource
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
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'width' => $this->width,
            'height' => $this->height,
            'review_status' => $this->review_status->value,
            'review_url' => $this->imageUrlGenerator->review($this->object_key),
            'uploader_phone_suffix' => Str::substr($this->user->phone, -4),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
