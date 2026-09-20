<?php

namespace App\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewPhotoRequest;
use App\Models\AdminReviewLog;
use App\Models\Photo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdminPhotoReviewController extends Controller
{
    public function __invoke(ReviewPhotoRequest $request, Photo $photo): JsonResponse
    {
        $reviewStatus = PhotoReviewStatus::from($request->string('status')->toString());
        $reviewedAt = now();

        $reviewedPhoto = DB::transaction(function () use ($photo, $reviewStatus, $reviewedAt): ?Photo {
            $updatedCount = Photo::query()
                ->whereKey($photo->getKey())
                ->where('upload_status', PhotoUploadStatus::Uploaded)
                ->where('review_status', PhotoReviewStatus::Pending)
                ->update([
                    'review_status' => $reviewStatus->value,
                    'reviewed_at' => $reviewedAt,
                ]);

            if ($updatedCount === 0) {
                return null;
            }

            AdminReviewLog::query()->create([
                'photo_id' => $photo->getKey(),
                'previous_status' => PhotoReviewStatus::Pending,
                'new_status' => $reviewStatus,
            ]);

            return $photo->refresh();
        });

        if ($reviewedPhoto === null) {
            return response()->json([
                'message' => '这张照片当前不可审核，请刷新后重试。',
                'code' => 'photo_not_pending_review',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'id' => $reviewedPhoto->id,
            'review_status' => $reviewedPhoto->review_status->value,
            'reviewed_at' => $reviewedPhoto->reviewed_at?->toISOString(),
        ]);
    }
}
