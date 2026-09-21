<?php

namespace App;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use Illuminate\Support\Collection;

final readonly class PhotoWeightBatchService
{
    public function __construct(
        private PhotoLikeInfluenceService $likeInfluenceService,
    ) {}

    /** @return Collection<int, CalculatedPhotoLotteryWeight> */
    public function calculate(): Collection
    {
        $photos = Photo::query()
            ->where('upload_status', PhotoUploadStatus::Uploaded)
            ->where('exclude_from_lottery', false)
            ->whereIn('review_status', [
                PhotoReviewStatus::Normal->value,
                PhotoReviewStatus::Good->value,
            ])
            ->orderBy('id')
            ->get();

        $likeInfluences = $this->likeInfluenceService->summarizeMany($photos);

        return $photos->mapWithKeys(static function (Photo $photo) use ($likeInfluences): array {
            $likeInfluence = $likeInfluences[$photo->id];

            return [
                $photo->id => new CalculatedPhotoLotteryWeight(
                    photo: $photo,
                    likeInfluence: $likeInfluence,
                    weight: PhotoLotteryWeight::calculate(
                        $photo->review_status,
                        $likeInfluence->voterTiers,
                    ),
                ),
            ];
        });
    }
}
