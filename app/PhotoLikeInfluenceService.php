<?php

namespace App;

use App\Enums\LikeVoterTier;
use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\Models\PhotoLike;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class PhotoLikeInfluenceService
{
    public function summarize(Photo $photo): PhotoLikeInfluenceSummary
    {
        $likeVoters = User::query()
            ->select('users.id')
            ->where('users.id', '!=', $photo->user_id)
            ->whereHas(
                'photoLikes',
                fn (Builder $query): Builder => $query->where('photo_likes.photo_id', $photo->id),
            )
            ->withExists([
                'photos as has_featured_photo' => fn (Builder $query): Builder => $query
                    ->where('upload_status', PhotoUploadStatus::Uploaded)
                    ->where('review_status', PhotoReviewStatus::Good),
                'photos as has_approved_photo' => fn (Builder $query): Builder => $query
                    ->where('upload_status', PhotoUploadStatus::Uploaded)
                    ->whereIn('review_status', [
                        PhotoReviewStatus::Normal->value,
                        PhotoReviewStatus::Good->value,
                    ]),
            ])
            ->orderBy('users.id')
            ->get();

        $voterTiers = $likeVoters
            ->map(self::voterTier(...))
            ->all();

        return new PhotoLikeInfluenceSummary($voterTiers);
    }

    /**
     * @param  iterable<Photo>  $photos
     * @return array<int, PhotoLikeInfluenceSummary>
     */
    public function summarizeMany(iterable $photos): array
    {
        $photosById = collect($photos)->keyBy('id');

        if ($photosById->isEmpty()) {
            return [];
        }

        $likesByPhotoId = PhotoLike::query()
            ->select(['photo_likes.photo_id', 'photo_likes.user_id'])
            ->join('photos as liked_photos', 'liked_photos.id', '=', 'photo_likes.photo_id')
            ->whereIn('photo_likes.photo_id', $photosById->keys())
            ->whereColumn('photo_likes.user_id', '!=', 'liked_photos.user_id')
            ->orderBy('photo_likes.user_id')
            ->get()
            ->groupBy('photo_id');

        $voterIds = $likesByPhotoId
            ->flatten(1)
            ->pluck('user_id')
            ->unique()
            ->values();

        $voterTiersByUserId = User::query()
            ->select('users.id')
            ->whereKey($voterIds)
            ->withExists([
                'photos as has_featured_photo' => fn (Builder $query): Builder => $query
                    ->where('upload_status', PhotoUploadStatus::Uploaded)
                    ->where('review_status', PhotoReviewStatus::Good),
                'photos as has_approved_photo' => fn (Builder $query): Builder => $query
                    ->where('upload_status', PhotoUploadStatus::Uploaded)
                    ->whereIn('review_status', [
                        PhotoReviewStatus::Normal->value,
                        PhotoReviewStatus::Good->value,
                    ]),
            ])
            ->get()
            ->mapWithKeys(
                static fn (User $user): array => [$user->id => self::voterTier($user)],
            );

        return $photosById
            ->mapWithKeys(function (Photo $photo) use ($likesByPhotoId, $voterTiersByUserId): array {
                $voterTiers = $likesByPhotoId
                    ->get($photo->id, collect())
                    ->map(
                        static fn (PhotoLike $like): ?LikeVoterTier => $voterTiersByUserId->get($like->user_id),
                    )
                    ->filter()
                    ->values()
                    ->all();

                return [$photo->id => new PhotoLikeInfluenceSummary($voterTiers)];
            })
            ->all();
    }

    private static function voterTier(User $user): LikeVoterTier
    {
        if ((bool) $user->getAttribute('has_featured_photo')) {
            return LikeVoterTier::Featured;
        }

        if ((bool) $user->getAttribute('has_approved_photo')) {
            return LikeVoterTier::Approved;
        }

        return LikeVoterTier::NoApprovedPhoto;
    }
}
