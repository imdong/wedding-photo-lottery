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
                'remaining_count' => 0,
            ]);
        }

        $photo = $pendingReviews
            ->with('user:id,phone')
            ->oldest()
            ->orderBy('id')
            ->firstOrFail();

        return response()->json([
            'photo' => (new AdminReviewPhotoResource($photo, $imageUrlGenerator))->resolve($request),
            'remaining_count' => $remainingCount,
        ]);
    }
}
