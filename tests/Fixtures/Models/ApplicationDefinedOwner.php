<?php

namespace Digit7s\FilamentSeo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ApplicationDefinedOwner extends Model
{
    protected $table = 'articles';

    public function seoMetadata(): MorphOne
    {
        return $this->morphOne(OwnerSeoMetadata::class, 'seoable');
    }
}
