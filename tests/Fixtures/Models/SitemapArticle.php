<?php

namespace Digit7s\FilamentSeo\Tests\Fixtures\Models;

use Digit7s\FilamentSeo\Concerns\HasSeoMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SitemapArticle extends Model
{
    use HasSeoMetadata;
    use SoftDeletes;

    protected $table = 'sitemap_articles';

    protected $fillable = ['id', 'slug', 'status', 'published_at'];

    protected $keyType = 'string';

    public $incrementing = false;
}
