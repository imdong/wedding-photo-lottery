<?php

namespace App;

use App\Models\User;
use Illuminate\Support\Collection;

final readonly class LotteryCandidatePoolService
{
    public function __construct(
        private PhotoWeightBatchService $photoWeightBatchService,
    ) {}

    public function build(): LotteryCandidatePool
    {
        $weightedPhotos = $this->photoWeightBatchService->calculate();

        if ($weightedPhotos->isEmpty()) {
            return new LotteryCandidatePool(collect(), 0.0);
        }

        $photosByUserId = $weightedPhotos->groupBy(
            static fn (CalculatedPhotoLotteryWeight $photo): int => $photo->photo->user_id,
            preserveKeys: true,
        );

        $users = User::query()
            ->whereKey($photosByUserId->keys())
            ->orderBy('id')
            ->get();

        $userCandidates = $users->mapWithKeys(
            static fn (User $user): array => [
                $user->id => LotteryUserCandidate::from(
                    $user,
                    $photosByUserId->get($user->id, new Collection),
                ),
            ],
        );

        return new LotteryCandidatePool(
            users: $userCandidates,
            totalWeight: (float) $userCandidates->sum('weight'),
        );
    }
}
