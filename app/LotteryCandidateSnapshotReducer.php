<?php

namespace App;

use UnexpectedValueException;

final class LotteryCandidateSnapshotReducer
{
    private const int WEIGHT_PRECISION = 6;

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function removeUser(array $snapshot, int $userId): array
    {
        $removed = false;
        $remainingUsers = [];

        foreach ($snapshot['users'] ?? [] as $user) {
            if (($user['user_id'] ?? null) === $userId) {
                $removed = true;

                continue;
            }

            $remainingUsers[] = $user;
        }

        if (! $removed) {
            throw new UnexpectedValueException('中奖用户不属于当前候选快照。');
        }

        $snapshot['users'] = $remainingUsers;
        $snapshot['total_weight'] = round(
            array_sum(array_map(
                static fn (array $user): float => (float) ($user['weight'] ?? 0.0),
                $remainingUsers,
            )),
            self::WEIGHT_PRECISION,
        );

        return $snapshot;
    }
}
