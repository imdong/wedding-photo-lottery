<?php

namespace App\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\User;
use App\OssObjectService;
use App\PhotoUploadProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompletedPhotoUploadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        Request $request,
        Photo $photo,
        OssObjectService $ossObjectService,
        PhotoUploadProcessingService $uploadProcessingService,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        if ($photo->user_id !== $user->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ($photo->upload_status === PhotoUploadStatus::Uploaded) {
            return $this->response($photo);
        }

        if (! $ossObjectService->exists($photo->object_key)) {
            return response()->json([
                'message' => '照片尚未上传完成，请重试。',
                'code' => 'object_not_found',
            ], Response::HTTP_CONFLICT);
        }

        $uploadProcessingService->process($photo);

        $photo->update([
            'upload_status' => PhotoUploadStatus::Uploaded,
            'review_status' => PhotoReviewStatus::Pending,
        ]);

        return $this->response($photo);
    }

    private function response(Photo $photo): JsonResponse
    {
        return response()->json([
            'id' => $photo->id,
            'upload_status' => $photo->upload_status->value,
            'review_status' => $photo->review_status->value,
        ]);
    }
}
