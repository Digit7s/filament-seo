<?php

use Digit7s\FilamentSeo\Tests\Fixtures\Models\Article;
use Digit7s\FilamentSeo\Tests\Support\CreatesSeoSchema;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(CreatesSeoSchema::class);

beforeEach(function (): void {
    $this->createSeoSchema();
    config()->set('filament-seo.fallbacks', ['default' => [], 'models' => []]);
    config()->set('filament-seo.defaults', ['title' => null, 'description' => null, 'image' => null]);
    config()->set('filament-seo.images.disk', null);
    config()->set('filament-seo.images.public_disks', []);
    config()->set('filament-seo.images.url_resolver', null);
    config()->set('filament-seo.images.allow_http', false);
});

function renderMeta(Article $record, array $fallbacks = []): string
{
    return Blade::render('<x-filament-seo::meta :record="$record" :fallbacks="$fallbacks" />', [
        'record' => $record,
        'fallbacks' => $fallbacks,
    ]);
}

it('renders stored and resolved metadata as escaped tags exactly once', function (): void {
    $article = Article::query()->create(['name' => 'Safe article']);
    $article->seoMetadata()->create([
        'meta_title' => 'A title with <script>alert(1)</script>',
        'meta_description' => 'A description with "quotes" & <b>markup</b>.',
        'keywords' => 'laravel, SEO & metadata',
        'canonical_url' => 'https://news.example.test/articles/safe',
        'og_title' => 'Social title',
        'og_description' => 'Social description',
        'og_image' => 'https://cdn.example.test/social.jpg',
        'og_image_alt' => 'A safe image',
        'twitter_title' => 'X title',
        'twitter_description' => 'X description',
        'twitter_image' => 'https://cdn.example.test/x.jpg',
        'twitter_image_alt' => 'X image alt',
        'robots_index' => true,
        'robots_follow' => false,
    ]);

    $html = renderMeta($article);

    expect(substr_count($html, '<title>'))->toBe(1)
        ->and(substr_count($html, 'name="description"'))->toBe(1)
        ->and(substr_count($html, 'rel="canonical"'))->toBe(1)
        ->and(substr_count($html, 'property="og:url"'))->toBe(1)
        ->and(substr_count($html, 'name="robots"'))->toBe(1)
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('content="index, nofollow"')
        ->and($html)->toContain('property="og:image:alt"')
        ->and($html)->toContain('name="twitter:card" content="summary_large_image"')
        ->and($html)->toContain('name="twitter:image:alt"');
});

it('uses explicit fallback values and omits absent optional metadata and robots defaults', function (): void {
    $article = Article::query()->create(['name' => 'Unconfigured article']);

    $html = renderMeta($article, [
        'title' => 'Consumer title',
        'description' => 'Consumer description',
    ]);

    expect($html)->toContain('<title>Consumer title</title>')
        ->and($html)->toContain('name="description" content="Consumer description"')
        ->and($html)->not->toContain('name="robots"')
        ->and($html)->not->toContain('rel="canonical"')
        ->and($html)->not->toContain('property="og:image"');

    config()->set('filament-seo.defaults.title', 'Configured site title');

    expect(renderMeta($article, ['title' => 'Lower-priority consumer title']))
        ->toContain('<title>Configured site title</title>')
        ->not->toContain('Lower-priority consumer title');
});

it('loads the configured relationship for saved records without leaking another record metadata', function (): void {
    $first = Article::query()->create(['name' => 'First article']);
    $second = Article::query()->create(['name' => 'Second article']);
    $metadata = $second->seoMetadata()->create([
        'meta_title' => 'Second record secret title',
        'robots_index' => false,
        'robots_follow' => true,
    ]);

    $first->setRelation('seoMetadata', $metadata);

    $html = renderMeta($first, ['title' => 'First record fallback']);

    expect($html)->toContain('<title>First record fallback</title>')
        ->and($html)->not->toContain('Second record secret title')
        ->and($html)->not->toContain('name="robots" content="noindex');
});

it('omits unsafe canonical URLs and private, missing, or inaccessible storage images', function (): void {
    Storage::fake('seo_private');
    config()->set('filament-seo.images.disk', 'seo_private');
    config()->set('filament-seo.images.public_disks', []);

    $article = Article::query()->create(['name' => 'Unsafe URLs']);
    $article->seoMetadata()->create([
        'canonical_url' => 'javascript:alert(1)',
        'og_image' => 'private/secret.jpg',
        'robots_index' => false,
        'robots_follow' => true,
    ]);

    $html = renderMeta($article);

    expect($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('rel="canonical"')
        ->and($html)->not->toContain('property="og:url"')
        ->and($html)->not->toContain('property="og:image"')
        ->and($html)->toContain('content="noindex, follow"');

    config()->set('filament-seo.images.disk', 'seo_public');
    config()->set('filament-seo.images.public_disks', ['seo_public']);
    Storage::fake('seo_public');

    $article->seoMetadata->update(['seo_image' => 'missing/file.jpg']);
    $missingFileHtml = renderMeta($article->fresh());

    expect($missingFileHtml)->not->toContain('property="og:image"');
});

it('does not emit HTTP URLs unless image policy explicitly permits them and never lazy-renders an unsaved model', function (): void {
    $article = new Article(['name' => 'Unsaved owner']);
    config()->set('filament-seo.images.url_resolver', fn (): string => 'http://cdn.example.test/image.jpg');
    config()->set('filament-seo.defaults.image', 'image.jpg');

    expect(renderMeta($article, ['title' => 'Preview title']))
        ->toContain('<title>Preview title</title>')
        ->not->toContain('http://cdn.example.test');

    config()->set('filament-seo.images.allow_http', true);

    expect(renderMeta($article))->toContain('http://cdn.example.test/image.jpg');
});

it('does not query the static page registry when rendering a loaded model record', function (): void {
    $article = Article::query()->create(['name' => 'Loaded article']);
    $article->seoMetadata()->create(['meta_title' => 'Loaded article title']);
    $article = $article->fresh()->load('seoMetadata');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $html = renderMeta($article);
    $queries = DB::getQueryLog();

    DB::disableQueryLog();

    expect($html)->toContain('<title>Loaded article title</title>')
        ->and($queries)->toBeEmpty();
});
