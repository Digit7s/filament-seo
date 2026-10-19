<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

use Digit7s\FilamentSeo\Models\SeoPage;
use Digit7s\FilamentSeo\Support\SeoMetadataResolver;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class StaticPageSitemapSource implements SitemapSource
{
    public function __construct(private readonly SeoMetadataResolver $resolver) {}

    public function entries(): iterable
    {
        if (! config('filament-seo.sitemap.static_pages.enabled', true)) {
            return;
        }

        $filter = ConfiguredCallback::resolve(config('filament-seo.sitemap.static_pages.filter'), 'static_pages.filter');

        foreach (SeoPage::query()->where('is_active', true)->with('seoMetadata')->orderBy('id')->lazyById(500) as $page) {
            if ($filter !== null && ! $filter($page)) {
                continue;
            }

            $metadata = $page->getRelation('seoMetadata');
            if (! $this->isIndexable($page, $metadata instanceof Model ? $metadata : null)) {
                continue;
            }

            // Route names may require parameters, so registered paths remain the safe public identity.
            $location = app(SitemapUrlPolicy::class)->canonicalOrPath($metadata?->getAttributeValue('canonical_url'), $page->path);
            if ($location !== null) {
                yield new SitemapEntry($location);
            }
        }
    }

    private function isIndexable(SeoPage $page, ?Model $metadata): bool
    {
        try {
            return $this->resolver->resolve($page, $metadata)->robotsIndex;
        } catch (Throwable) {
            return false;
        }
    }
}
