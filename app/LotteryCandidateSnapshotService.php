<?php

namespace App;

use App\Enums\DrawRoundStatus;
use App\Models\DrawRound;
use App\Models\DrawWinner;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class LotteryCandidateSnapshotService
{
    private const int WEIGHT_PRECISION = 6;

    private const string SELECTION_MODE = 'user_then_photo';

    public function __construct(
        private LotteryCandidatePoolService $candidatePoolService,
    ) {}

    public function freeze(DrawRound $drawRound): DrawRound
    {
        return DB::transaction(function () use ($drawRound): DrawRound {
            $lockedRound = DrawRound::query()
                ->lockForUpdate()
                ->findOrFail($drawRound->id);

            if (
                $lockedRound->status !== DrawRoundStatus::Prepared
                || $lockedRound->candidate_snapshot !== []
            ) {
                throw new LogicException('抽奖轮次候选快照已经冻结。');
            }

            $excludePreviousWinners = (bool) config(
                'lottery.exclude_previous_winners',
                true,
            );
            $excludedUserIds = $excludePreviousWinners
                ? DrawWinner::query()->distinct()->pluck('user_id')
                : collect();
            $candidatePool = $this->candidatePoolService->build();

            $snapshotUsers = $candidatePool->users
                ->except($excludedUserIds)
                ->map(static function (LotteryUserCandidate $userCandidate): array {
                    $photos = $userCandidate->photos
                        ->map(static fn (CalculatedPhotoLotteryWeight $photo): array => [
                            'photo_id' => $photo->photo->id,
                            'object_key' => $photo->photo->object_key,
                            'lottery_status' => $photo->weight->status->value,
                            'effective_likes' => round(
                                $photo->weight->effectiveLikes,
                                self::WEIGHT_PRECISION,
                            ),
                            'like_bonus' => round(
                                $photo->weight->likeBonus,
                                self::WEIGHT_PRECISION,
                            ),
                            'weight' => round(
                                $photo->weight->weight,
                                self::WEIGHT_PRECISION,
                            ),
                        ])
                        ->values();

                    return [
                        'user_id' => $userCandidate->user->id,
                        'phone_suffix' => substr($userCandidate->user->phone, -4),
                        'weight' => round(
                            (float) $photos->sum('weight'),
                            self::WEIGHT_PRECISION,
                        ),
                        'photos' => $photos->all(),
                    ];
                })
                ->values();

            $lockedRound->update([
                'candidate_snapshot' => [
                    'total_weight' => round(
                        (float) $snapshotUsers->sum('weight'),
                        self::WEIGHT_PRECISION,
                    ),
                    'users' => $snapshotUsers->all(),
                ],
                'settings_snapshot' => [
                    'draw_count' => $lockedRound->draw_count,
                    'exclude_previous_winners' => $excludePreviousWinners,
                    'selection_mode' => self::SELECTION_MODE,
                ],
            ]);

            return $lockedRound->refresh();
        });
    }
}
