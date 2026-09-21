<?php

namespace App\Http\Controllers\Api;

use App\Enums\DrawRoundStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DrawResultResource;
use App\Models\DrawRound;
use App\OssImageUrlGenerator;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DrawWinnerController extends Controller
{
    public function __invoke(Request $request, OssImageUrlGenerator $imageUrlGenerator): JsonResponse
    {
        $rounds = DrawRound::query()
            ->select([
                'id',
                'round_number',
                'status',
                'completed_at',
            ])
            ->where('status', DrawRoundStatus::Completed)
            ->whereHas('winners')
            ->with([
                'winners' => fn (HasMany $query): HasMany => $query
                    ->select([
                        'id',
                        'draw_round_id',
                        'user_id',
                        'photo_id',
                        'photo_weight',
                        'position',
                    ])
                    ->orderBy('position'),
                'winners.user:id,phone',
                'winners.photo:id,user_id,object_key',
            ])
            ->orderByDesc('round_number')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'rounds' => $rounds
                ->map(fn (DrawRound $round): array => (new DrawResultResource($round, $imageUrlGenerator))->resolve($request))
                ->all(),
        ]);
    }
}
