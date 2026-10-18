<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Support;

use Digit7s\FilamentSeo\Data\ResolvedSeoMetadata;
use Digit7s\FilamentSeo\Data\SeoCompleteness;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Evaluates configured editorial requirements without persistence or queries. */
final class SeoCompletenessAnalyzer
{
    /**
     * Configured requirements use the fixed storage field names. Values are
     * checked against resolved/effective values so configured fallbacks count.
     *
     * @param  list<string>|null  $requiredFields  Optional per-call override.
     */
    public function analyze(
        ?Model $metadata,
        ResolvedSeoMetadata $resolved,
        bool $relationshipLoaded = true,
        ?array $requiredFields = null,
    ): SeoCompleteness {
        $requirements = $requiredFields ?? config(
            'filament-seo.completeness.required',
            ['meta_title', 'meta_description'],
        );

        if (! is_array($requirements)) {
            throw new InvalidArgumentException('SEO completeness requirements must be an array of field names.');
        }

        foreach ($requirements as $field) {
            if (! is_string($field)) {
                throw new InvalidArgumentException('SEO completeness requirements must contain only field-name strings.');
            }
        }

        $requirements = array_values(array_unique($requirements));
        $hasStoredMetadata = $metadata !== null;

        if (! $relationshipLoaded) {
            return new SeoCompleteness(
                status: SeoCompleteness::STATUS_NOT_LOADED,
                issues: [],
                hasStoredMetadata: $hasStoredMetadata,
                requiredFields: $requirements,
            );
        }

        $issues = [];

        foreach ($requirements as $field) {
            if (! isset($this->fieldMap()[$field])) {
                throw new InvalidArgumentException("Unsupported SEO completeness field [{$field}].");
            }

            if ($this->valueFor($resolved, $field) === null) {
                $issues[] = ['id' => 'missing_'.$field, 'field' => $field];
            }
        }

        $status = $issues === []
            ? SeoCompleteness::STATUS_COMPLETE
            : ($hasStoredMetadata
                ? SeoCompleteness::STATUS_NEEDS_ATTENTION
                : SeoCompleteness::STATUS_NO_STORED_METADATA);

        return new SeoCompleteness(
            status: $status,
            issues: $issues,
            hasStoredMetadata: $hasStoredMetadata,
            requiredFields: $requirements,
        );
    }

    /** @return array<string, string> */
    private function fieldMap(): array
    {
        return [
            'meta_title' => 'title',
            'meta_description' => 'description',
            'canonical_url' => 'canonicalUrl',
            'seo_image' => 'seoImageUrl',
            'seo_image_alt' => 'seoImageAlt',
            'og_title' => 'ogTitle',
            'og_description' => 'ogDescription',
            'og_image' => 'ogImageUrl',
            'og_image_alt' => 'ogImageAlt',
            'twitter_title' => 'twitterTitle',
            'twitter_description' => 'twitterDescription',
            'twitter_image' => 'twitterImageUrl',
            'twitter_image_alt' => 'twitterImageAlt',
        ];
    }

    private function valueFor(ResolvedSeoMetadata $resolved, string $field): ?string
    {
        $property = $this->fieldMap()[$field];

        return $resolved->{$property};
    }
}
