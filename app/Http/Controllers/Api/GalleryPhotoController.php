<?php

namespace App\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\GalleryPhotoResource;
use App\Models\Photo;
use App\Models\User;
use App\OssImageUrlGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GalleryPhotoController extends Controller
{
    private const PER_PAGE = 20;

    public function __invoke(Request $request, OssImageUrlGenerator $imageUrlGenerator): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $photos = Photo::query()
            ->withCount([
                'likes as like_count' => fn (Builder $query): Builder => $query
                    ->whereColumn('photo_likes.user_id', '!=', 'photos.user_id'),
            ])
            ->withExists([
                'likes as liked_by_me' => fn (Builder $query): Builder => $query
                    ->where('user_id', $user->id),
            ])
            ->where('upload_status', PhotoUploadStatus::Uploaded)
            ->whereIn('review_status', [
                PhotoReviewStatus::Normal->value,
                PhotoReviewStatus::Good->value,
            ])
            ->latest()
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'photos' => $photos->getCollection()
                ->map(fn (Photo $photo): array => (new GalleryPhotoResource($photo, $imageUrlGenerator))->resolve($request))
                ->all(),
            'next_cursor' => $photos->nextCursor()?->encode(),
            'has_more' => $photos->hasMorePages(),
        ]);
    }
}
