<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Filament\Infolists;

use Digit7s\FilamentSeo\Data\ResolvedSeoMetadata;
use Digit7s\FilamentSeo\Data\SeoCompleteness;
use Digit7s\FilamentSeo\Support\SeoCompletenessAnalyzer;
use Digit7s\FilamentSeo\Support\SeoMetadataResolver;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Displays stored and optionally effective metadata without lazy loading. */
final class SeoMetadataInfolistSection
{
    /** @var array<string, list<string>> */
    private const GROUPS = [
        'basic' => ['meta_title', 'meta_description', 'keywords', 'canonical_url', 'seo_image', 'seo_image_alt'],
        'social' => ['og_title', 'og_description', 'og_image', 'og_image_alt', 'twitter_title', 'twitter_description', 'twitter_image', 'twitter_image_alt'],
        'robots' => ['robots_index', 'robots_follow'],
    ];

    /** @var list<string> */
    private const FIELDS = ['meta_title', 'meta_description', 'keywords', 'canonical_url', 'seo_image', 'seo_image_alt', 'og_title', 'og_description', 'og_image', 'og_image_alt', 'twitter_title', 'twitter_description', 'twitter_image', 'twitter_image_alt', 'robots_index', 'robots_follow'];

    /** @param list<string> $groups */
    public static function make(
        string $relationship = 'seoMetadata',
        array $groups = ['basic', 'social', 'robots'],
        bool $showResolvedValues = true,
        bool $showCompleteness = true,
        bool $imagePreview = false,
    ): Section {
        foreach ($groups as $group) {
            if (! isset(self::GROUPS[$group])) {
                throw new InvalidArgumentException("Unsupported SEO field group [{$group}].");
            }
        }

        $fields = array_values(array_unique(array_merge(...array_map(fn (string $group): array => self::GROUPS[$group], $groups ?: []))));
        $entries = [];

        foreach ($fields as $field) {
            $entries[] = self::entry($field, $relationship, $imagePreview);
        }

        if ($showResolvedValues) {
            $resolvedFields = [];

            if (in_array('basic', $groups, true)) {
                $resolvedFields = [
                    'resolved_title' => 'title',
                    'resolved_description' => 'description',
                    'resolved_canonical_url' => 'canonicalUrl',
                    'resolved_seo_image' => 'seoImageUrl',
                    'resolved_seo_image_alt' => 'seoImageAlt',
                ];
            }

            if (in_array('social', $groups, true)) {
                $resolvedFields = [
                    ...$resolvedFields,
                    'resolved_og_title' => 'ogTitle',
                    'resolved_og_description' => 'ogDescription',
                    'resolved_og_image' => 'ogImageUrl',
                    'resolved_og_image_alt' => 'ogImageAlt',
                    'resolved_twitter_title' => 'twitterTitle',
                    'resolved_twitter_description' => 'twitterDescription',
                    'resolved_twitter_image' => 'twitterImageUrl',
                    'resolved_twitter_image_alt' => 'twitterImageAlt',
                ];
            }

            foreach ($resolvedFields as $name => $property) {
                $entries[] = TextEntry::make($name)
                    ->label(fn (): string => __('filament-seo::filament-seo.fields.'.$name))
                    ->state(fn (Model $record): ?string => self::resolved($record, $relationship)?->{$property});
            }
        }

        if ($showCompleteness) {
            $entries[] = TextEntry::make('seo_completeness')
                ->label(fn (): string => __('filament-seo::filament-seo.fields.completeness'))
                ->state(function (Model $record) use ($relationship): string {
                    $result = self::analyze($record, $relationship);

                    return __('filament-seo::filament-seo.statuses.'.$result->status);
                });
        }

        return Section::make(fn (): string => __('filament-seo::filament-seo.groups.seo'))->schema($entries);
    }

    private static function entry(string $field, string $relationship, bool $imagePreview): TextEntry|ImageEntry
    {
        $label = fn (): string => __('filament-seo::filament-seo.fields.'.$field);

        if (in_array($field, ['seo_image', 'og_image', 'twitter_image'], true) && $imagePreview) {
            return ImageEntry::make($field)
                ->label($label)
                ->state(fn (Model $record): ?string => self::resolvedImage($record, $relationship, $field))
                ->imageHeight(120)
                ->checkFileExistence(false)
                ->placeholder(fn (Model $record): string => self::placeholder($record, $relationship, $field));
        }

        return TextEntry::make($field)
            ->label($label)
            ->state(fn (Model $record): mixed => self::storedValue($record, $relationship, $field))
            ->placeholder(fn (Model $record): string => self::placeholder($record, $relationship, $field));
    }

    private static function storedValue(Model $owner, string $relationship, string $field): mixed
    {
        $metadata = self::related($owner, $relationship);

        if (! $metadata) {
            return null;
        }

        $value = $metadata->getAttributeValue($field);

        if (in_array($field, ['robots_index', 'robots_follow'], true)) {
            return $value === null ? null : ($value ? __('filament-seo::filament-seo.values.enabled') : __('filament-seo::filament-seo.values.disabled'));
        }

        return $value;
    }

    private static function resolved(Model $owner, string $relationship): ?ResolvedSeoMetadata
    {
        if (! $owner->relationLoaded($relationship)) {
            return null;
        }

        $metadata = $owner->getRelation($relationship);

        return app(SeoMetadataResolver::class)->resolve($owner, $metadata instanceof Model ? $metadata : null);
    }

    private static function resolvedImage(Model $owner, string $relationship, string $field): ?string
    {
        $property = match ($field) {
            'seo_image' => 'seoImageUrl',
            'og_image' => 'ogImageUrl',
            default => 'twitterImageUrl',
        };

        return self::resolved($owner, $relationship)?->{$property};
    }

    private static function related(Model $owner, string $relationship): ?Model
    {
        if (! $owner->relationLoaded($relationship)) {
            return null;
        }

        $metadata = $owner->getRelation($relationship);

        return $metadata instanceof Model ? $metadata : null;
    }

    private static function placeholder(Model $owner, string $relationship, string $field): string
    {
        if (! $owner->relationLoaded($relationship)) {
            return __('filament-seo::filament-seo.placeholders.not_loaded');
        }

        $metadata = self::related($owner, $relationship);

        if (! $metadata) {
            return __('filament-seo::filament-seo.placeholders.missing_metadata');
        }

        return $metadata->getAttributeValue($field) === null
            ? __('filament-seo::filament-seo.placeholders.stored_value_missing')
            : '';
    }

    private static function analyze(Model $owner, string $relationship): SeoCompleteness
    {
        $loaded = $owner->relationLoaded($relationship);
        $metadata = $loaded ? $owner->getRelation($relationship) : null;
        $resolved = app(SeoMetadataResolver::class)->resolve($owner, $metadata instanceof Model ? $metadata : null);

        return app(SeoCompletenessAnalyzer::class)->analyze(
            metadata: $metadata instanceof Model ? $metadata : null,
            resolved: $resolved,
            relationshipLoaded: $loaded,
        );
    }
}
