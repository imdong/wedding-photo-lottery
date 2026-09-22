<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreparePhotoUploadsRequest;
use App\Models\User;
use App\OssTemporaryCredentialService;
use App\PhotoUploadPreparationService;
use Illuminate\Http\JsonResponse;

class PreparedPhotoUploadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        PreparePhotoUploadsRequest $request,
        PhotoUploadPreparationService $preparationService,
        OssTemporaryCredentialService $credentialService,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        /** @var list<array{original_file_hash: string, upload_file_hash: string, filename: string, original_mime_type: string, original_file_size: int, upload_mime_type: string, upload_file_size: int}> $photos */
        $photos = $request->validated('photos');
        $credentials = $credentialService->issueForUser($user);

        return response()->json([
            'credentials' => $credentials->toArray(),
            'photos' => $preparationService->prepare($user, $photos),
        ]);
    }
}
