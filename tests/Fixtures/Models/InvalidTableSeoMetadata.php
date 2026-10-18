<?php

namespace Digit7s\FilamentSeo\Tests\Fixtures\Models;

use Digit7s\FilamentSeo\Models\SeoMetadata;

class InvalidTableSeoMetadata extends SeoMetadata
{
    protected $table = 'other_seo_metadata';
}
