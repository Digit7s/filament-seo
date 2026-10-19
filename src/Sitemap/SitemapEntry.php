<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

final readonly class SitemapEntry
{
    public function __construct(
        public string $location,
        public ?string $lastModified = null,
    ) {}
}
