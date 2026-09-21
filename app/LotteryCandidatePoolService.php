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
        $maxPhotosPerUser = max(1, (int) config('lottery.max_photos_per_user', 15));

        if ($weightedPhotos->isEmpty()) {
            return new LotteryCandidatePool(collect(), 0.0);
        }

        $photosByUserId = $weightedPhotos->groupBy(
            static fn (CalculatedPhotoLotteryWeight $photo): int => $photo->photo->user_id,
            preserveKeys: true,
        );
        $limitedPhotosByUserId = $photosByUserId->map(
            fn (Collection $photos): Collection => $this->selectCandidatePhotos(
                $photos,
                $maxPhotosPerUser,
            ),
        );

        $users = User::query()
            ->whereKey($limitedPhotosByUserId->keys())
            ->orderBy('id')
            ->get();

        $userCandidates = $users->mapWithKeys(
            static fn (User $user): array => [
                $user->id => LotteryUserCandidate::from(
                    $user,
                    $limitedPhotosByUserId->get($user->id, new Collection),
                ),
            ],
        );

        return new LotteryCandidatePool(
            users: $userCandidates,
            totalWeight: (float) $userCandidates->sum('weight'),
        );
    }

    /**
     * @param  Collection<int, CalculatedPhotoLotteryWeight>  $photos
     * @return Collection<int, CalculatedPhotoLotteryWeight>
     */
    private function selectCandidatePhotos(Collection $photos, int $maxPhotosPerUser): Collection
    {
        if ($photos->count() <= $maxPhotosPerUser) {
            return $photos;
        }

        return $photos
            ->sort(static function (CalculatedPhotoLotteryWeight $left, CalculatedPhotoLotteryWeight $right): int {
                $likeBonusComparison = $right->weight->likeBonus <=> $left->weight->likeBonus;

                if ($likeBonusComparison !== 0) {
                    return $likeBonusComparison;
                }

                $weightComparison = $right->weight->weight <=> $left->weight->weight;

                if ($weightComparison !== 0) {
                    return $weightComparison;
                }

                return $left->photo->id <=> $right->photo->id;
            })
            ->take($maxPhotosPerUser)
            ->sortBy(static fn (CalculatedPhotoLotteryWeight $photo): int => $photo->photo->id)
            ->keyBy(static fn (CalculatedPhotoLotteryWeight $photo): int => $photo->photo->id);
    }
}
