<?php

namespace App\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserPhotoStatsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->loadCount([
            'photos as uploaded_count' => fn (Builder $query): Builder => $query
                ->where('upload_status', PhotoUploadStatus::Uploaded),
            'photos as pending_count' => fn (Builder $query): Builder => $query
                ->where('upload_status', PhotoUploadStatus::Uploaded)
                ->where('review_status', PhotoReviewStatus::Pending),
            'photos as approved_count' => fn (Builder $query): Builder => $query
                ->where('upload_status', PhotoUploadStatus::Uploaded)
                ->whereIn('review_status', [
                    PhotoReviewStatus::Normal,
                    PhotoReviewStatus::Good,
                ]),
            'photos as rejected_count' => fn (Builder $query): Builder => $query
                ->where('upload_status', PhotoUploadStatus::Uploaded)
                ->where('review_status', PhotoReviewStatus::Rejected),
        ]);

        return response()->json([
            'uploaded_count' => (int) $user->getAttribute('uploaded_count'),
            'pending_count' => (int) $user->getAttribute('pending_count'),
            'approved_count' => (int) $user->getAttribute('approved_count'),
            'rejected_count' => (int) $user->getAttribute('rejected_count'),
        ]);
    }
}
