<?php

namespace Digit7s\FilamentSeo\Tests\Fixtures\Sitemap;

use Digit7s\FilamentSeo\Sitemap\SitemapEntry;
use Digit7s\FilamentSeo\Sitemap\SitemapSource;

class SyntheticSitemapSource implements SitemapSource
{
    public function entries(): iterable
    {
        $count = (int) config('filament-seo.sitemap.synthetic_count', 0);
        $length = max(0, (int) config('filament-seo.sitemap.synthetic_length', 0));
        for ($i = 1; $i <= $count; $i++) {
            yield new SitemapEntry(sprintf('https://example.test/synthetic/%06d', $i).str_repeat('x', $length));
        }
    }
}
