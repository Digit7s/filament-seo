<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Filament\Tables;

use Digit7s\FilamentSeo\Data\SeoScoreResult;
use Digit7s\FilamentSeo\Support\SeoMetadataResolver;
use Digit7s\FilamentSeo\Support\SeoScoreCalculator;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

/** Displays a cached metadata-quality score for an explicitly eager-loaded relation. */
final class SeoScoreColumn
{
    public static function make(string $name = 'seo_score', ?string $relationship = null): TextColumn
    {
        $relationship ??= (string) config('filament-seo.relationship', 'seoMetadata');
        $cache = new WeakMap;

        $resultFor = static function (Model $record) use ($relationship, $cache): SeoScoreResult {
            if (isset($cache[$record])) {
                return $cache[$record];
            }

            if (! $record->relationLoaded($relationship)) {
                return $cache[$record] = SeoScoreResult::notLoaded();
            }

            $metadata = $record->getRelation($relationship);
            $metadata = $metadata instanceof Model ? $metadata : null;
            $resolved = app(SeoMetadataResolver::class)->resolve($record, $metadata);
            $result = app(SeoScoreCalculator::class)->calculate($metadata, $resolved);

            return $cache[$record] = $result;
        };

        return TextColumn::make($name)
            ->label(fn (): string => __('filament-seo::filament-seo.groups.seo_score'))
            ->badge()
            ->state(static fn (Model $record): string => $resultFor($record)->label())
            ->color(static fn (Model $record): string => $resultFor($record)->color())
            ->icon(static fn (Model $record): string => $resultFor($record)->icon())
            ->tooltip(static fn (Model $record): string => $resultFor($record)->summary())
            ->toggleable();
    }
}
