<?php

namespace App;

use InvalidArgumentException;
use UnexpectedValueException;

final readonly class LotteryWinnerSequenceSelector
{
    public function __construct(
        private LotteryWinnerSelector $winnerSelector,
        private LotteryCandidateSnapshotReducer $snapshotReducer,
    ) {}

    /**
     * @param  array<string, mixed>  $candidateSnapshot
     */
    public function select(array $candidateSnapshot, int $drawCount): LotteryDrawSequenceResult
    {
        if ($drawCount < 0) {
            throw new InvalidArgumentException('连续抽取数量不能为负数。');
        }

        $remainingSnapshot = $candidateSnapshot;
        $selections = [];

        for ($position = 0; $position < $drawCount; $position++) {
            $selection = $this->winnerSelector->select($remainingSnapshot);
            $selectedUserId = $selection->user['user_id'] ?? null;

            if (! is_int($selectedUserId)) {
                throw new UnexpectedValueException('候选快照缺少有效用户编号。');
            }

            $selections[] = $selection;
            $remainingSnapshot = $this->snapshotReducer->removeUser(
                $remainingSnapshot,
                $selectedUserId,
            );
        }

        return new LotteryDrawSequenceResult($selections, $remainingSnapshot);
    }
}
