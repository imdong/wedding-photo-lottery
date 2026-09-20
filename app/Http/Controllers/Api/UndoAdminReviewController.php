<?php

namespace App\Http\Controllers\Api;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminReviewLog;
use App\Models\Photo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class UndoAdminReviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $result = DB::transaction(function (): array {
            $reviewLog = AdminReviewLog::query()
                ->whereNull('undone_at')
                ->latest('created_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($reviewLog === null) {
                return ['status' => 'empty'];
            }

            $photo = Photo::query()
                ->whereKey($reviewLog->photo_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($photo->upload_status !== PhotoUploadStatus::Uploaded
                || $photo->review_status !== $reviewLog->new_status) {
                return ['status' => 'changed'];
            }

            $photo->update([
                'review_status' => $reviewLog->previous_status,
                'reviewed_at' => $reviewLog->previous_status === PhotoReviewStatus::Pending
                    ? null
                    : $photo->reviewed_at,
            ]);

            $reviewLog->update(['undone_at' => now()]);

            return [
                'status' => 'undone',
                'photo' => $photo,
            ];
        });

        if ($result['status'] === 'empty') {
            return response()->json([
                'message' => '没有可撤销的审核记录。',
                'code' => 'nothing_to_undo',
            ], Response::HTTP_CONFLICT);
        }

        if ($result['status'] === 'changed') {
            return response()->json([
                'message' => '最近审核的照片状态已经变化，请刷新后重试。',
                'code' => 'review_state_changed',
            ], Response::HTTP_CONFLICT);
        }

        /** @var Photo $photo */
        $photo = $result['photo'];

        return response()->json([
            'id' => $photo->id,
            'review_status' => $photo->review_status->value,
            'reviewed_at' => $photo->reviewed_at?->toISOString(),
        ]);
    }
}
