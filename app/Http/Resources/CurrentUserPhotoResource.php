<?php

namespace App\Http\Resources;

use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\OssImageUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Photo */
class CurrentUserPhotoResource extends JsonResource
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
            'upload_status' => $this->upload_status->value,
            'review_status' => $this->review_status->value,
            'thumbnail_url' => $this->when(
                $this->upload_status === PhotoUploadStatus::Uploaded,
                fn (): string => $this->imageUrlGenerator->thumbnail($this->object_key),
            ),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
