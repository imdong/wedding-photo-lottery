<?php

namespace Tests\Unit;

use App\Enums\LikeVoterTier;
use App\Enums\LotteryPhotoStatus;
use App\Enums\PhotoReviewStatus;
use App\PhotoLotteryWeight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhotoLotteryWeightTest extends TestCase
{
    #[DataProvider('ignoredReviewStatuses')]
    public function test_ignored_photo_has_zero_weight_even_with_likes(PhotoReviewStatus $reviewStatus): void
    {
        $weight = PhotoLotteryWeight::calculate($reviewStatus, [
            LikeVoterTier::Featured,
            LikeVoterTier::Featured,
        ]);

        $this->assertSame(LotteryPhotoStatus::Ignored, $weight->status);
        $this->assertSame(0.0, $weight->effectiveLikes);
        $this->assertSame(0.0, $weight->likeBonus);
        $this->assertSame(0.0, $weight->weight);
    }

    #[DataProvider('approvedPhotoBaseWeights')]
    public function test_approved_photo_has_expected_base_weight(
        PhotoReviewStatus $reviewStatus,
        LotteryPhotoStatus $expectedStatus,
        float $expectedWeight,
    ): void {
        $weight = PhotoLotteryWeight::calculate($reviewStatus);

        $this->assertSame($expectedStatus, $weight->status);
        $this->assertSame(0.0, $weight->effectiveLikes);
        $this->assertSame(0.0, $weight->likeBonus);
        $this->assertSame($expectedWeight, $weight->weight);
    }

    public function test_like_voter_tiers_contribute_their_current_multipliers(): void
    {
        $weight = PhotoLotteryWeight::calculate(PhotoReviewStatus::Normal, [
            LikeVoterTier::NoApprovedPhoto,
            LikeVoterTier::Approved,
            LikeVoterTier::Featured,
        ]);
        $expectedBonus = 0.15 * sqrt(2.5);

        $this->assertSame(2.5, $weight->effectiveLikes);
        $this->assertEqualsWithDelta($expectedBonus, $weight->likeBonus, 0.0000001);
        $this->assertEqualsWithDelta(1.0 + $expectedBonus, $weight->weight, 0.0000001);
    }

    #[DataProvider('likeVoterTierMultipliers')]
    public function test_each_like_voter_tier_has_expected_multiplier(
        LikeVoterTier $likeVoterTier,
        float $expectedContribution,
    ): void {
        $weight = PhotoLotteryWeight::calculate(PhotoReviewStatus::Normal, [$likeVoterTier]);

        $this->assertSame($expectedContribution, $weight->effectiveLikes);
        $this->assertEqualsWithDelta(
            1.0 + (0.15 * sqrt($expectedContribution)),
            $weight->weight,
            0.0000001,
        );
    }

    #[DataProvider('cappedPhotoWeights')]
    public function test_effective_likes_are_capped_at_sixteen(
        PhotoReviewStatus $reviewStatus,
        float $expectedWeight,
    ): void {
        $weight = PhotoLotteryWeight::calculate(
            $reviewStatus,
            array_fill(0, 20, LikeVoterTier::Featured),
        );

        $this->assertSame(16.0, $weight->effectiveLikes);
        $this->assertSame(0.6, $weight->likeBonus);
        $this->assertEqualsWithDelta($expectedWeight, $weight->weight, 0.0000001);
    }

    public function test_probability_is_photo_weight_divided_by_total_weight(): void
    {
        $weight = PhotoLotteryWeight::calculate(PhotoReviewStatus::Good);

        $this->assertEqualsWithDelta(0.3, $weight->probability(6.0), 0.0000001);
        $this->assertSame(0.0, $weight->probability(0.0));
    }

    /** @return array<string, array{PhotoReviewStatus}> */
    public static function ignoredReviewStatuses(): array
    {
        return [
            'pending' => [PhotoReviewStatus::Pending],
            'rejected' => [PhotoReviewStatus::Rejected],
        ];
    }

    /** @return array<string, array{PhotoReviewStatus, LotteryPhotoStatus, float}> */
    public static function approvedPhotoBaseWeights(): array
    {
        return [
            'normal maps to approved' => [PhotoReviewStatus::Normal, LotteryPhotoStatus::Approved, 1.0],
            'good maps to featured' => [PhotoReviewStatus::Good, LotteryPhotoStatus::Featured, 1.8],
        ];
    }

    /** @return array<string, array{PhotoReviewStatus, float}> */
    public static function cappedPhotoWeights(): array
    {
        return [
            'approved maximum' => [PhotoReviewStatus::Normal, 1.6],
            'featured maximum' => [PhotoReviewStatus::Good, 2.4],
        ];
    }

    /** @return array<string, array{LikeVoterTier, float}> */
    public static function likeVoterTierMultipliers(): array
    {
        return [
            'no approved photo' => [LikeVoterTier::NoApprovedPhoto, 0.25],
            'approved photo' => [LikeVoterTier::Approved, 1.0],
            'featured photo' => [LikeVoterTier::Featured, 1.25],
        ];
    }
}
