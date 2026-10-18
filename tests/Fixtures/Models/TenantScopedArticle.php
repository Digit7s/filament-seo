<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Tests\Fixtures\Models;

use Digit7s\FilamentSeo\Concerns\HasSeoMetadata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantScopedArticle extends Model
{
    use HasSeoMetadata;

    protected $table = 'articles';

    protected $fillable = ['name'];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn (Builder $query) => $query->where('name', 'like', 'tenant-a%'));
    }
}
