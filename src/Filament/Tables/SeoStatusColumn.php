<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Filament\Tables;

use Digit7s\FilamentSeo\Data\SeoCompleteness;
use Digit7s\FilamentSeo\Support\SeoCompletenessAnalyzer;
use Digit7s\FilamentSeo\Support\SeoMetadataResolver;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/** Displays analyzed status for an explicitly eager-loaded relationship. */
final class SeoStatusColumn
{
    public static function make(
        string $relationship = 'seoMetadata',
        bool $issuesTooltip = false,
        bool $showCompleteness = false,
    ): TextColumn {
        $column = TextColumn::make('seo_status')
            ->label(fn (): string => __('filament-seo::filament-seo.groups.seo'))
            ->badge()
            ->state(function (Model $record) use ($relationship, $showCompleteness): string {
                $completeness = self::analyze($record, $relationship);
                $label = __('filament-seo::filament-seo.statuses.'.$completeness->status);

                if ($showCompleteness && $completeness->issues !== []) {
                    $issueLabels = array_map(
                        fn (array $issue): string => __('filament-seo::filament-seo.fields.'.$issue['field']),
                        $completeness->issues,
                    );
                    $label .= ' · '.__('filament-seo::filament-seo.messages.missing_fields', ['fields' => implode(', ', $issueLabels)]);
                }

                return $label;
            })
            ->color(fn (Model $record): string => match (self::analyze($record, $relationship)->status) {
                SeoCompleteness::STATUS_COMPLETE => 'success',
                SeoCompleteness::STATUS_NOT_LOADED => 'gray',
                SeoCompleteness::STATUS_NEEDS_ATTENTION, SeoCompleteness::STATUS_NO_STORED_METADATA => 'warning',
                default => 'gray',
            });

        if ($issuesTooltip) {
            $column->tooltip(function (Model $record) use ($relationship): ?string {
                $result = self::analyze($record, $relationship);

                if ($result->status === SeoCompleteness::STATUS_NOT_LOADED) {
                    return __('filament-seo::filament-seo.statuses.not_loaded');
                }

                if ($result->issues === []) {
                    return null;
                }

                return implode("\n", array_map(
                    fn (array $issue): string => __('filament-seo::filament-seo.issues.'.$issue['id']),
                    $result->issues,
                ));
            });
        }

        return $column;
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
