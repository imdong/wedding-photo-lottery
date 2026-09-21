<?php

namespace App;

use App\Enums\DrawRoundStatus;
use App\Exceptions\DrawRoundNotDrawableException;
use App\Exceptions\InvalidDrawSnapshotException;
use App\Exceptions\NoWeightedCandidatesException;
use App\Models\DrawRound;
use App\Models\DrawWinner;
use App\Models\Photo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class ExecuteDrawRoundService
{
    public function __construct(private LotteryWinnerSequenceSelector $winnerSequenceSelector) {}

    /**
     * @throws DrawRoundNotDrawableException
     * @throws InvalidDrawSnapshotException
     */
    public function execute(DrawRound $drawRound): DrawRound
    {
        return DB::transaction(function () use ($drawRound): DrawRound {
            $lockedRound = DrawRound::query()
                ->lockForUpdate()
                ->findOrFail($drawRound->getKey());

            if ($lockedRound->status === DrawRoundStatus::Completed) {
                $this->assertCompletedRoundIsConsistent($lockedRound);

                return $this->loadWinners($lockedRound);
            }

            if ($lockedRound->status !== DrawRoundStatus::Prepared) {
                throw new DrawRoundNotDrawableException;
            }

            if ($lockedRound->winners()->exists()) {
                throw new InvalidDrawSnapshotException('准备中的轮次不能包含中奖记录。');
            }

            try {
                $result = $this->winnerSequenceSelector->select(
                    $lockedRound->candidate_snapshot,
                    $lockedRound->draw_count,
                );
            } catch (NoWeightedCandidatesException|InvalidArgumentException|UnexpectedValueException $exception) {
                throw new InvalidDrawSnapshotException('冻结候选快照无法完成抽奖。', previous: $exception);
            }

            $validatedSelections = $this->validateSelections($result->selections);

            foreach ($validatedSelections as $position => $selection) {
                DrawWinner::query()->create([
                    'draw_round_id' => $lockedRound->id,
                    'user_id' => $selection['user_id'],
                    'photo_id' => $selection['photo_id'],
                    'photo_weight' => $selection['photo_weight'],
                    'position' => $position + 1,
                ]);
            }

            $lockedRound->update([
                'status' => DrawRoundStatus::Completed,
                'completed_at' => now(),
            ]);

            return $this->loadWinners($lockedRound->refresh());
        });
    }

    /**
     * @param  list<LotterySelection>  $selections
     * @return list<array{user_id: int, photo_id: int, photo_weight: float}>
     */
    private function validateSelections(array $selections): array
    {
        $validatedSelections = [];

        foreach ($selections as $selection) {
            $userId = $selection->user['user_id'] ?? null;
            $photoId = $selection->photo['photo_id'] ?? null;
            $photoWeight = $selection->photo['weight'] ?? null;

            if (! is_int($userId) || ! is_int($photoId) || ! is_numeric($photoWeight)) {
                throw new InvalidDrawSnapshotException('中奖结果缺少有效候选信息。');
            }

            $normalizedPhotoWeight = (float) $photoWeight;

            if (! is_finite($normalizedPhotoWeight) || $normalizedPhotoWeight <= 0) {
                throw new InvalidDrawSnapshotException('中奖照片权重无效。');
            }

            $validatedSelections[] = [
                'user_id' => $userId,
                'photo_id' => $photoId,
                'photo_weight' => $normalizedPhotoWeight,
            ];
        }

        $photoOwners = Photo::query()
            ->whereKey(collect($validatedSelections)->pluck('photo_id'))
            ->pluck('user_id', 'id');

        foreach ($validatedSelections as $selection) {
            if (! $photoOwners->has($selection['photo_id'])
                || (int) $photoOwners->get($selection['photo_id']) !== $selection['user_id']) {
                throw new InvalidDrawSnapshotException('中奖照片与候选用户不匹配。');
            }
        }

        return $validatedSelections;
    }

    private function assertCompletedRoundIsConsistent(DrawRound $drawRound): void
    {
        if ($drawRound->winners()->count() !== $drawRound->draw_count) {
            throw new InvalidDrawSnapshotException('已完成轮次的中奖记录不完整。');
        }
    }

    private function loadWinners(DrawRound $drawRound): DrawRound
    {
        $winners = $drawRound->winners()
            ->with([
                'user:id,phone',
                'photo:id,user_id,object_key',
            ])
            ->orderBy('position')
            ->get();

        return $drawRound->setRelation('winners', $winners);
    }
}
