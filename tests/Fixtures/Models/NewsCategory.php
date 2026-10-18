<?php

namespace Digit7s\FilamentSeo\Tests\Fixtures\Models;

use Digit7s\FilamentSeo\Concerns\HasSeoMetadata;
use Illuminate\Database\Eloquent\Model;

class NewsCategory extends Model
{
    use HasSeoMetadata;

    protected $table = 'news_categories';

    protected $fillable = ['name'];
}
