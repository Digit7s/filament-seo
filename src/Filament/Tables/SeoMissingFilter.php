<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Filament\Tables;

use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/** Filters by stored values, independent of owner fallbacks. */
final class SeoMissingFilter
{
    /** @var list<string> */
    private const FIELDS = [
        'meta_title', 'meta_description', 'keywords', 'canonical_url', 'seo_image', 'seo_image_alt',
        'og_title', 'og_description', 'og_image', 'og_image_alt',
        'twitter_title', 'twitter_description', 'twitter_image', 'twitter_image_alt',
    ];

    /** @param list<string> $requiredFields */
    public static function make(
        string $name = 'seo_missing',
        string $relationship = 'seoMetadata',
        array $requiredFields = ['meta_title', 'meta_description'],
    ): Filter {
        foreach ($requiredFields as $field) {
            if (! is_string($field) || ! in_array($field, self::FIELDS, true)) {
                throw new InvalidArgumentException('Unsupported SEO missing-filter field ['.(is_scalar($field) ? (string) $field : get_debug_type($field)).'].');
            }
        }

        $requiredFields = array_values(array_unique($requiredFields));

        return Filter::make($name)
            ->label(fn (): string => __('filament-seo::filament-seo.filters.missing'))
            ->query(function (Builder $query) use ($relationship, $requiredFields): void {
                $query->where(function (Builder $query) use ($relationship, $requiredFields): void {
                    $query->whereDoesntHave($relationship);

                    if ($requiredFields === []) {
                        return;
                    }

                    $query->orWhereHas($relationship, function (Builder $related) use ($requiredFields): void {
                        $related->where(function (Builder $missing) use ($requiredFields): void {
                            foreach ($requiredFields as $index => $field) {
                                $condition = $index === 0 ? 'where' : 'orWhere';
                                $qualified = $missing->getModel()->qualifyColumn($field);
                                $wrapped = $missing->getQuery()->getGrammar()->wrap($qualified);
                                $whitespace = $wrapped;
                                $bindings = [];

                                foreach (["\t", "\n", "\v", "\f", "\r"] as $character) {
                                    $whitespace = "REPLACE({$whitespace}, ?, '')";
                                    $bindings[] = $character;
                                }

                                $bindings[] = '';
                                $missing->{$condition}(function (Builder $fieldQuery) use ($field, $whitespace, $bindings): void {
                                    $fieldQuery->whereNull($field)
                                        ->orWhereRaw("TRIM({$whitespace}) = ?", $bindings);
                                });
                            }
                        });
                    });
                });
            });
    }
}
