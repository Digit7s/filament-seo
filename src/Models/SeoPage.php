<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Models;

use Digit7s\FilamentSeo\Concerns\HasSeoMetadata;
use Digit7s\FilamentSeo\Support\SeoPagePath;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** A registered frontend route/path with metadata only; it never stores page content. */
class SeoPage extends Model
{
    use HasSeoMetadata;

    protected $table = 'seo_pages';

    /** @var list<string> */
    protected $fillable = ['name', 'page_key', 'route_name', 'path', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $page): void {
            $page->path = SeoPagePath::normalize((string) $page->path);
            $page->page_key = trim((string) $page->page_key);
            $routeName = trim((string) $page->route_name);
            $page->route_name = $routeName === '' ? null : $routeName;

            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $page->page_key) !== 1) {
                throw new InvalidArgumentException('SEO page keys must use lowercase letters or numbers separated by hyphens.');
            }
        });

        static::deleting(static function (self $page): void {
            $page->seoMetadata()->delete();
        });
    }

    public function setPathAttribute(string $value): void
    {
        $this->attributes['path'] = SeoPagePath::normalize($value);
    }
}
