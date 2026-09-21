<?php

namespace App\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DrawPhotoResource;
use App\Models\Photo;
use App\OssImageUrlGenerator;
use Illuminate\Http\JsonResponse;

class DrawPhotoController extends Controller
{
    public function __invoke(OssImageUrlGenerator $imageUrlGenerator): JsonResponse
    {
        $photos = Photo::query()
            ->select(['id', 'object_key'])
            ->where('upload_status', PhotoUploadStatus::Uploaded)
            ->whereIn('review_status', [
                PhotoReviewStatus::Normal->value,
                PhotoReviewStatus::Good->value,
            ])
            ->orderBy('id')
            ->get();

        return response()->json([
            'photos' => $photos
                ->map(fn (Photo $photo): array => (new DrawPhotoResource($photo, $imageUrlGenerator))->resolve())
                ->all(),
        ]);
    }
}
