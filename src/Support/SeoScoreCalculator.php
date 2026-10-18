<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Support;

use Digit7s\FilamentSeo\Data\ResolvedSeoMetadata;
use Digit7s\FilamentSeo\Data\SeoScoreResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Calculates an explainable metadata-quality score without database access. */
final class SeoScoreCalculator
{
    public const GOOD_SCORE_THRESHOLD = 80;

    /**
     * Calculate using effective values already resolved by the caller.
     *
     * @param  Model|null  $metadata  Null means the loaded relationship has no row.
     */
    public function calculate(?Model $metadata, ResolvedSeoMetadata $resolved): SeoScoreResult
    {
        if ($metadata === null) {
            return SeoScoreResult::notConfigured();
        }

        $earned = 0;
        $available = 0;
        $findings = [];
        $applicableRules = [];
        $skippedRules = [];

        $evaluate = static function (string $rule, int $weight, bool $applicable, bool $passed) use (&$earned, &$available, &$applicableRules, &$skippedRules, &$findings): void {
            if (! $applicable) {
                $skippedRules[] = $rule;

                return;
            }

            $applicableRules[] = $rule;
            $available += $weight;

            if ($passed) {
                $earned += $weight;

                return;
            }

            $findings[] = $rule;
        };

        $hasTitle = $resolved->title !== null;
        $evaluate('meta_title', 30, true, $hasTitle);
        $evaluate(
            'meta_title_length',
            10,
            $hasTitle,
            $hasTitle && self::withinRange(Str::length($resolved->title), 50, 60),
        );

        $hasDescription = $resolved->description !== null;
        $evaluate('meta_description', 30, true, $hasDescription);
        $evaluate(
            'meta_description_length',
            10,
            $hasDescription,
            $hasDescription && self::withinRange(Str::length($resolved->description), 150, 160),
        );

        $rawCanonical = $metadata->getAttributeValue('canonical_url');
        $canonicalConfigured = self::hasText($rawCanonical) || $resolved->canonicalUrl !== null;
        $evaluate(
            'canonical_url',
            10,
            $canonicalConfigured,
            $resolved->canonicalUrl !== null,
        );

        $imageConfigured = self::hasText($metadata->getAttributeValue('seo_image'))
            || self::hasText($metadata->getAttributeValue('og_image'))
            || self::hasText($metadata->getAttributeValue('twitter_image'))
            || $resolved->ogImageUrl !== null
            || $resolved->twitterImageUrl !== null;
        $evaluate(
            'social_image_url',
            10,
            $imageConfigured,
            $resolved->ogImageUrl !== null || $resolved->twitterImageUrl !== null,
        );

        $score = (int) round(($earned / $available) * 100);
        $status = ! $resolved->robotsIndex
            ? SeoScoreResult::STATUS_EXCLUDED
            : ($score >= self::GOOD_SCORE_THRESHOLD
                ? SeoScoreResult::STATUS_GOOD
                : SeoScoreResult::STATUS_NEEDS_IMPROVEMENT);

        return new SeoScoreResult(
            score: $score,
            status: $status,
            findings: $findings,
            applicableRules: $applicableRules,
            skippedRules: $skippedRules,
        );
    }

    private static function withinRange(int $length, int $minimum, int $maximum): bool
    {
        return $length >= $minimum && $length <= $maximum;
    }

    private static function hasText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
