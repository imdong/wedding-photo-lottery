<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhotoUnlikeController extends Controller
{
    public function __invoke(Request $request, Photo $photo): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $photo->likes()
            ->where('user_id', $user->id)
            ->delete();

        return response()->json([
            'photo_id' => $photo->id,
            'liked' => false,
        ]);
    }
}
