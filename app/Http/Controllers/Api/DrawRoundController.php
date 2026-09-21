<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DrawRoundNotDrawableException;
use App\Exceptions\InvalidDrawSnapshotException;
use App\ExecuteDrawRoundService;
use App\Http\Controllers\Controller;
use App\Http\Resources\DrawResultResource;
use App\Models\DrawRound;
use App\OssImageUrlGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DrawRoundController extends Controller
{
    public function __invoke(
        Request $request,
        DrawRound $drawRound,
        ExecuteDrawRoundService $executeDrawRound,
        OssImageUrlGenerator $imageUrlGenerator,
    ): JsonResponse {
        try {
            $completedRound = $executeDrawRound->execute($drawRound);
        } catch (DrawRoundNotDrawableException) {
            return response()->json([
                'message' => '当前轮次不能执行抽奖。',
                'code' => 'draw_round_not_prepared',
            ], Response::HTTP_CONFLICT);
        } catch (InvalidDrawSnapshotException) {
            return response()->json([
                'message' => '当前抽奖数据无效，请重新准备抽奖。',
                'code' => 'invalid_draw_snapshot',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(
            (new DrawResultResource($completedRound, $imageUrlGenerator))->resolve($request),
        );
    }
}
