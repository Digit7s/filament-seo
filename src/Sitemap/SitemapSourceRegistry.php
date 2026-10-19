<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

use Digit7s\FilamentSeo\Support\SeoMetadataResolver;
use InvalidArgumentException;

final class SitemapSourceRegistry
{
    /** @return iterable<SitemapSource> */
    public function sources(): iterable
    {
        if (config('filament-seo.sitemap.static_pages.enabled', true)) {
            yield app(StaticPageSitemapSource::class);
        }

        $models = config('filament-seo.sitemap.models', []);
        if (! is_array($models)) {
            throw new InvalidArgumentException('The sitemap models setting must be an array keyed by Eloquent model class.');
        }

        foreach ($models as $modelClass => $options) {
            if (! is_string($modelClass) || ! is_array($options)) {
                throw new InvalidArgumentException('Each sitemap model source must map a model class name to an options array.');
            }
            if (($options['enabled'] ?? true) === false) {
                continue;
            }

            yield new ModelSitemapSource($modelClass, $options, app(SeoMetadataResolver::class));
        }

        $extensions = config('filament-seo.sitemap.sources', []);
        if (! is_array($extensions)) {
            throw new InvalidArgumentException('The sitemap sources setting must be an array of SitemapSource classes.');
        }
        foreach ($extensions as $sourceClass) {
            if (! is_string($sourceClass) || ! class_exists($sourceClass)) {
                throw new InvalidArgumentException('Each custom sitemap source must be an instantiable class name.');
            }
            $source = app($sourceClass);
            if (! $source instanceof SitemapSource) {
                throw new InvalidArgumentException("Custom sitemap source [{$sourceClass}] must implement SitemapSource.");
            }
            yield $source;
        }
    }
}
