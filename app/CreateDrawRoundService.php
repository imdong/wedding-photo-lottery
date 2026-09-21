<?php

namespace App;

use App\Enums\DrawRoundStatus;
use App\Exceptions\InsufficientDrawCandidatesException;
use App\Models\DrawRound;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class CreateDrawRoundService
{
    public function __construct(
        private LotteryCandidateSnapshotService $candidateSnapshotService,
    ) {}

    public function create(): DrawRound
    {
        $drawCount = (int) config('lottery.draw_count', 1);

        if ($drawCount < 1) {
            throw new LogicException('抽奖人数配置必须至少为 1。');
        }

        return DB::transaction(function () use ($drawCount): DrawRound {
            $lastRound = DrawRound::query()
                ->latest('round_number')
                ->lockForUpdate()
                ->first();

            if (
                $lastRound?->status === DrawRoundStatus::Prepared
                && $lastRound->candidate_snapshot !== []
                && $lastRound->draw_count === $drawCount
            ) {
                return $lastRound;
            }

            $drawRound = DrawRound::query()->create([
                'round_number' => ($lastRound?->round_number ?? 0) + 1,
                'draw_count' => $drawCount,
            ]);
            $frozenRound = $this->candidateSnapshotService->freeze($drawRound);
            $availableCount = count($frozenRound->candidate_snapshot['users']);

            if ($availableCount < $drawCount) {
                throw new InsufficientDrawCandidatesException(
                    requiredCount: $drawCount,
                    availableCount: $availableCount,
                );
            }

            return $frozenRound;
        });
    }
}
