<?php

namespace App\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminReviewPhotoResource;
use App\Models\Photo;
use App\OssImageUrlGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NextAdminReviewController extends Controller
{
    public function __invoke(Request $request, OssImageUrlGenerator $imageUrlGenerator): JsonResponse
    {
        $pendingReviews = Photo::query()
            ->where('upload_status', PhotoUploadStatus::Uploaded)
            ->where('review_status', PhotoReviewStatus::Pending);

        $remainingCount = (clone $pendingReviews)->count();

        if ($remainingCount === 0) {
            return response()->json([
                'photo' => null,
                'next_photo' => null,
                'remaining_count' => 0,
            ]);
        }

        $photos = $pendingReviews
            ->with('user:id,phone')
            ->oldest()
            ->orderBy('id')
            ->limit(2)
            ->get();

        $photo = $photos->get(0);
        $nextPhoto = $photos->get(1);

        return response()->json([
            'photo' => $photo === null
                ? null
                : (new AdminReviewPhotoResource($photo, $imageUrlGenerator))->resolve($request),
            'next_photo' => $nextPhoto === null
                ? null
                : (new AdminReviewPhotoResource($nextPhoto, $imageUrlGenerator))->resolve($request),
            'remaining_count' => $remainingCount,
        ]);
    }
}
