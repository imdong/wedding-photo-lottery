<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CurrentUserPhotoResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserPhotoController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $photos = $user->photos()
            ->latest()
            ->get();

        return response()->json([
            'photos' => CurrentUserPhotoResource::collection($photos)->resolve($request),
        ]);
    }
}
