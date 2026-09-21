<?php

namespace App\Http\Controllers\Api;

use App\CreateDrawRoundService;
use App\Exceptions\InsufficientDrawCandidatesException;
use App\Http\Controllers\Controller;
use App\Http\Resources\DrawRoundResource;
use App\OssImageUrlGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminDrawRoundController extends Controller
{
    public function __invoke(
        Request $request,
        CreateDrawRoundService $createDrawRound,
        OssImageUrlGenerator $imageUrlGenerator,
    ): JsonResponse {
        try {
            $drawRound = $createDrawRound->create();
        } catch (InsufficientDrawCandidatesException $exception) {
            return response()->json([
                'message' => '可参与抽奖的人数不足，请先完成照片审核。',
                'code' => 'insufficient_draw_candidates',
                'required_count' => $exception->requiredCount,
                'available_count' => $exception->availableCount,
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(
            (new DrawRoundResource($drawRound, $imageUrlGenerator))->resolve($request),
            Response::HTTP_CREATED,
        );
    }
}
