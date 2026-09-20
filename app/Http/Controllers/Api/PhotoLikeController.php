<?php

namespace App\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PhotoLikeController extends Controller
{
    public function __invoke(Request $request, Photo $photo): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($photo->user_id === $user->id) {
            return response()->json([
                'message' => '不能给自己上传的照片点赞。',
                'code' => 'cannot_like_own_photo',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($photo->upload_status !== PhotoUploadStatus::Uploaded
            || ! in_array($photo->review_status, [PhotoReviewStatus::Normal, PhotoReviewStatus::Good], true)) {
            return response()->json([
                'message' => '这张照片当前不能点赞。',
                'code' => 'photo_not_likeable',
            ], Response::HTTP_CONFLICT);
        }

        $photo->likes()->firstOrCreate(['user_id' => $user->id]);

        return response()->json([
            'photo_id' => $photo->id,
            'liked' => true,
        ]);
    }
}
