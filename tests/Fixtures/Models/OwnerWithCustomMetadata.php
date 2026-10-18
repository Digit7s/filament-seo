<?php

namespace Digit7s\FilamentSeo\Tests\Fixtures\Models;

use Digit7s\FilamentSeo\Concerns\HasSeoMetadata;
use Illuminate\Database\Eloquent\Model;

class OwnerWithCustomMetadata extends Model
{
    use HasSeoMetadata;

    protected $table = 'articles';

    protected $fillable = ['name'];

    protected function seoMetadataModel(): string
    {
        return OwnerSeoMetadata::class;
    }
}
