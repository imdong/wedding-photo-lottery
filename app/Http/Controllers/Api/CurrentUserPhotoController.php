<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CurrentUserPhotoResource;
use App\Models\Photo;
use App\Models\User;
use App\OssImageUrlGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserPhotoController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, OssImageUrlGenerator $imageUrlGenerator): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $photos = $user->photos()
            ->latest()
            ->get();

        return response()->json([
            'photos' => $photos
                ->map(fn (Photo $photo): array => (new CurrentUserPhotoResource($photo, $imageUrlGenerator))->resolve($request))
                ->all(),
        ]);
    }
}
