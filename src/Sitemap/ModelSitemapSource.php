<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

use Digit7s\FilamentSeo\Support\SeoMetadataResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Throwable;

/** Iterates only explicitly configured models using stable, relation-eager-loaded keyset chunks. */
final class ModelSitemapSource implements SitemapSource
{
    /** @param class-string<Model> $modelClass @param array<string, mixed> $options */
    public function __construct(
        private readonly string $modelClass,
        private readonly array $options,
        private readonly SeoMetadataResolver $resolver,
    ) {}

    public function entries(): iterable
    {
        $modelClass = $this->modelClass;
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException("Configured sitemap model [{$modelClass}] must be an Eloquent model.");
        }

        $model = new $modelClass;
        $relation = $this->options['relationship'] ?? config('filament-seo.relationship', 'seoMetadata');
        if (! is_string($relation) || $relation === '') {
            throw new InvalidArgumentException("Sitemap model [{$modelClass}] has an invalid metadata relationship name.");
        }

        $query = $model->newQuery();
        if (method_exists($model, $relation)) {
            $query->with($relation);
        }

        $constraint = $this->options['query'] ?? null;
        if ($constraint !== null) {
            $result = ConfiguredCallback::resolve($constraint, "models.{$modelClass}.query")($query);
            if ($result instanceof Builder) {
                $query = $result;
            } elseif ($result !== null) {
                throw new InvalidArgumentException("Sitemap model [{$modelClass}] query callback must return Builder or null.");
            }
        }

        $chunkSize = (int) config('filament-seo.sitemap.model_chunk_size', 500);
        $keyName = $model->getKeyName();
        $qualifiedKey = $model->qualifyColumn($keyName);

        // Callbacks may constrain and select columns, but URL order is always the model key.
        foreach ($query->reorder()->lazyById($chunkSize, $qualifiedKey, $keyName) as $record) {
            if (! $this->isIndexable($record, $relation)) {
                continue;
            }

            $location = $this->resolveLocation($record);
            if ($location === null) {
                continue;
            }

            yield new SitemapEntry($location, $this->resolveLastModified($record));
        }
    }

    private function isIndexable(Model $record, string $relation): bool
    {
        $metadata = $record->relationLoaded($relation) ? $record->getRelation($relation) : null;
        if (! $metadata instanceof Model) {
            return true;
        }

        try {
            return $this->resolver->resolve($record, $metadata)->robotsIndex;
        } catch (Throwable) {
            return false;
        }
    }

    private function resolveLocation(Model $record): ?string
    {
        $relation = $this->options['relationship'] ?? config('filament-seo.relationship', 'seoMetadata');
        $metadata = $record->relationLoaded($relation) ? $record->getRelation($relation) : null;
        $canonical = $metadata instanceof Model ? $metadata->getAttributeValue('canonical_url') : null;
        $url = $this->options['url'] ?? null;
        if ($url !== null) {
            $candidate = ConfiguredCallback::resolve($url, "models.{$this->modelClass}.url")($record);

            return app(SitemapUrlPolicy::class)->canonicalOrPath($canonical, is_string($candidate) ? $candidate : '');
        }

        $routeName = $this->options['route'] ?? null;
        Route::getRoutes()->refreshNameLookups();
        if (! is_string($routeName) || $routeName === '' || ! Route::has($routeName)) {
            return null;
        }

        $routeKey = $this->options['route_key'] ?? $record->getRouteKeyName();
        if (! is_string($routeKey) || $routeKey === '') {
            throw new InvalidArgumentException("Sitemap model [{$this->modelClass}] route_key must be an attribute name.");
        }
        $value = $record->getAttributeValue($routeKey);
        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        try {
            // Generate relative URLs so request Host headers never choose the sitemap origin.
            $routeParameter = $this->options['route_parameter'] ?? $routeKey;
            if (! is_string($routeParameter) || $routeParameter === '') {
                throw new InvalidArgumentException("Sitemap model [{$this->modelClass}] route_parameter must be a parameter name.");
            }
            $relative = route($routeName, [$routeParameter => $value], false);
        } catch (Throwable) {
            return null;
        }

        return app(SitemapUrlPolicy::class)->canonicalOrPath(
            $canonical,
            $relative,
        );
    }

    private function resolveLastModified(Model $record): ?string
    {
        $value = $this->options['lastmod'] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_string($value) && $value !== '' && ! class_exists($value) && ! str_contains($value, '@') && ! is_callable($value)) {
            $value = $record->getAttributeValue($value);
        } else {
            $value = ConfiguredCallback::resolve($value, "models.{$this->modelClass}.lastmod")($record);
        }

        return SitemapDate::format($value);
    }
}
