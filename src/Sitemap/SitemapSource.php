<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

/** A trusted application-configured source of candidate public URLs. */
interface SitemapSource
{
    /** @return iterable<SitemapEntry> */
    public function entries(): iterable;
}
