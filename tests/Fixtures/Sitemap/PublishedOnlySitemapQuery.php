<?php

namespace Digit7s\FilamentSeo\Tests\Fixtures\Sitemap;

use Illuminate\Database\Eloquent\Builder;

class PublishedOnlySitemapQuery
{
    public function __invoke(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
