<?php

use Digit7s\FilamentSeo\Models\SeoPage;
use Digit7s\FilamentSeo\Support\StructuredDataGenerator;
use Digit7s\FilamentSeo\Tests\Fixtures\Components\SeoFormHarness;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\Article;
use Digit7s\FilamentSeo\Tests\Support\CreatesSeoSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(CreatesSeoSchema::class);

beforeEach(function (): void {
    $this->createSeoSchema();
    request()->attributes->set('filament-seo.json-ld.types', []);
    config()->set('filament-seo.structured_data.site.enabled', false);
    config()->set('filament-seo.structured_data.model_mappings', []);
});

function renderJsonLd(?Article $record = null, ?string $page = null, ?string $route = null): string
{
    return Blade::render('<x-filament-seo::json-ld :record="$record" :page="$page" :route="$route" />', compact('record', 'page', 'route'));
}

function jsonLdPayload(string $html): ?array
{
    if (preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $matches) !== 1) {
        return null;
    }

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

it('keeps JSON-LD opt-in and leaves the existing meta component unchanged', function (): void {
    $article = Article::query()->create(['name' => 'News']);
    $article->seoMetadata()->create(['meta_title' => 'News title', 'meta_description' => 'News summary']);

    expect(renderJsonLd($article))->toBe('')
        ->and(Blade::render('<x-filament-seo::meta :record="$record" />', ['record' => $article->fresh()]))
        ->toContain('<title>News title</title>');
});

it('persists structured-data settings and properties through the shared SeoFields relationship', function (): void {
    $article = Article::query()->create(['name' => 'Form article']);

    Livewire::test(SeoFormHarness::class, ['recordId' => $article->getKey()])
        ->set('withStructuredData', true)
        ->set('data.seoMetadata.meta_title', 'Form article headline')
        ->set('data.seoMetadata.structured_data_enabled', true)
        ->set('data.seoMetadata.structured_data_type', 'NewsArticle')
        ->set('data.seoMetadata.structured_data.author', 'Reporter')
        ->set('data.seoMetadata.structured_data.datePublished', '2026-08-09')
        ->call('save')
        ->assertHasNoErrors();

    expect($article->seoMetadata()->firstOrFail()->only([
        'structured_data_enabled', 'structured_data_type', 'structured_data',
    ]))->toMatchArray([
        'structured_data_enabled' => true,
        'structured_data_type' => 'NewsArticle',
        'structured_data' => ['author' => 'Reporter', 'datePublished' => '2026-08-09'],
    ]);
});

it('renders explicit NewsArticle data using resolved SEO values and explicit author/date only', function (): void {
    $article = Article::query()->create(['name' => 'Model heading']);
    $article->seoMetadata()->create([
        'meta_title' => 'Resolved headline',
        'meta_description' => 'Resolved description',
        'canonical_url' => 'https://example.test/news/one',
        'seo_image' => 'https://cdn.example.test/news.jpg',
        'structured_data_enabled' => true,
        'structured_data_type' => 'NewsArticle',
        'structured_data' => ['author' => 'A. Writer', 'datePublished' => '2026-02-03T12:30:00+07:00'],
    ]);

    $payload = jsonLdPayload(renderJsonLd($article->fresh()));

    expect($payload)->toMatchArray([
        '@context' => 'https://schema.org', '@type' => 'NewsArticle',
        'headline' => 'Resolved headline', 'description' => 'Resolved description',
        'url' => 'https://example.test/news/one', 'image' => 'https://cdn.example.test/news.jpg',
        'author' => ['@type' => 'Person', 'name' => 'A. Writer'],
        'datePublished' => '2026-02-03T12:30:00+07:00',
    ])->not->toHaveKey('dateModified')
        ->and($payload)->not->toHaveKey('publisher');
});

it('renders the same stored schema through an active static page and omits inactive or unknown pages', function (): void {
    $page = SeoPage::query()->create(['name' => 'About', 'page_key' => 'about-us', 'path' => '/about-us']);
    $page->seoMetadata()->create([
        'meta_title' => 'About Example', 'meta_description' => 'About description',
        'canonical_url' => 'https://example.test/about-us',
        'structured_data_enabled' => true, 'structured_data_type' => 'AboutPage',
    ]);

    expect(jsonLdPayload(renderJsonLd(page: 'about-us')))->toMatchArray([
        '@type' => 'AboutPage', 'name' => 'About Example', 'description' => 'About description',
        'url' => 'https://example.test/about-us',
    ])
        ->and(renderJsonLd(page: 'not-registered'))->toBe('');

    $page->update(['is_active' => false]);

    expect(renderJsonLd(page: 'about-us'))->toBe('');
});

it('supports exact named-route and configured current-request resolution for static pages', function (): void {
    Route::get('/json-ld-contact', static fn () => 'contact')->name('json-ld.contact');
    $page = SeoPage::query()->create([
        'name' => 'Contact', 'page_key' => 'contact', 'route_name' => 'json-ld.contact', 'path' => '/json-ld-contact',
    ]);
    $page->seoMetadata()->create([
        'meta_title' => 'Contact us', 'canonical_url' => 'https://example.test/contact',
        'structured_data_enabled' => true, 'structured_data_type' => 'ContactPage',
    ]);

    expect(jsonLdPayload(Blade::render('<x-filament-seo::json-ld route="json-ld.contact" />')))->toMatchArray([
        '@type' => 'ContactPage', 'name' => 'Contact us', 'url' => 'https://example.test/contact',
    ]);

    $request = Request::create('/json-ld-contact', 'GET');
    $route = app('router')->getRoutes()->match($request);
    $request->setRouteResolver(static fn () => $route);
    app()->instance('request', $request);
    config()->set('filament-seo.static_pages.resolve_current_request', true);
    request()->attributes->set('filament-seo.json-ld.types', []);

    expect(jsonLdPayload(Blade::render('<x-filament-seo::json-ld />')))->toMatchArray(['@type' => 'ContactPage']);
});

it('generates valid objects for each supported type and positions breadcrumbs in order', function (): void {
    $generator = app(StructuredDataGenerator::class);

    foreach (['WebPage', 'AboutPage', 'ContactPage'] as $type) {
        expect($generator->build($type, [], ['name' => 'Page name', 'description' => 'Page description', 'url' => 'https://example.test/page']))
            ->toMatchArray(['@context' => 'https://schema.org', '@type' => $type, 'name' => 'Page name']);
    }

    expect($generator->build('WebSite', ['name' => 'Example', 'url' => 'https://example.test']))
        ->toMatchArray(['@type' => 'WebSite', 'name' => 'Example', 'url' => 'https://example.test'])
        ->and($generator->build('Organization', ['name' => 'Example', 'url' => 'https://example.test', 'sameAs' => ['https://social.example.test/example']]))
        ->toMatchArray(['@type' => 'Organization', 'sameAs' => ['https://social.example.test/example']])
        ->and($generator->build('Article', [], ['headline' => 'Article headline']))
        ->toMatchArray(['@type' => 'Article', 'headline' => 'Article headline'])
        ->and($generator->build('NewsArticle', [], ['headline' => 'News headline']))
        ->toMatchArray(['@type' => 'NewsArticle', 'headline' => 'News headline']);

    $breadcrumbs = $generator->build('BreadcrumbList', ['itemListElement' => [
        ['name' => 'Home', 'url' => 'https://example.test/'],
        ['name' => 'News', 'url' => 'https://example.test/news'],
        ['name' => 'Current article', 'url' => ''],
    ]]);

    expect($breadcrumbs['itemListElement'])->toBe([
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://example.test/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'News', 'item' => 'https://example.test/news'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Current article'],
    ])
        ->and($generator->build('BreadcrumbList', ['itemListElement' => [['name' => 'Invalid', 'url' => 'javascript:alert(1)']]]))->toBeNull();
});

it('reports incomplete, invalid, and unsupported structured data without changing SEO scores', function (): void {
    $generator = app(StructuredDataGenerator::class);

    expect($generator->validationIssues('NotASchemaType', []))->toHaveKey('schema_type')
        ->and($generator->validationIssues('NewsArticle', ['datePublished' => 'not a date']))->toHaveKeys(['invalid_date', 'missing_headline'])
        ->and($generator->validationIssues('WebSite', ['name' => 'Example', 'url' => 'javascript:alert(1)']))->toHaveKeys(['invalid_url', 'missing_url'])
        ->and($generator->validationIssues('WebPage', ['unexpected' => 'ignored']))->toHaveKey('unsupported_property')
        ->and($generator->validationIssues('BreadcrumbList', ['itemListElement' => [['name' => 'Only', 'url' => 'javascript:alert(1)']]]))->toHaveKey('invalid_breadcrumb_list');
});

it('rejects unsafe URLs and serializes script-breaking values with HTML-safe JSON flags', function (): void {
    $article = Article::query()->create(['name' => 'Injection test']);
    $article->seoMetadata()->create([
        'meta_title' => '</script><script>alert("x")</script>',
        'canonical_url' => 'https://example.test/injection',
        'structured_data_enabled' => true, 'structured_data_type' => 'WebPage',
        'structured_data' => ['url' => 'javascript:alert(1)', 'description' => 'Unicode ✓ & "quoted"'],
    ]);

    $html = renderJsonLd($article->fresh());
    $payload = jsonLdPayload($html);

    expect(substr_count($html, '<script'))->toBe(1)
        ->and($html)->not->toContain('</script><script>')
        ->and($payload['description'])->toBe('Unicode ✓ & "quoted"')
        ->and($payload['url'])->toBe('https://example.test/injection');
});

it('keeps canonical and breadcrumb URL policy HTTPS-only even when HTTP image URLs are allowed', function (): void {
    config()->set('filament-seo.images.allow_http', true);
    $generator = app(StructuredDataGenerator::class);

    expect($generator->build('WebPage', ['name' => 'Page', 'url' => 'http://example.test/page']))
        ->toMatchArray(['@type' => 'WebPage', 'name' => 'Page'])
        ->not->toHaveKey('url')
        ->and($generator->build('BreadcrumbList', ['itemListElement' => [
            ['name' => 'Last item', 'url' => 'http://example.test/page'],
        ]]))->toBeNull()
        ->and($generator->build('NewsArticle', ['image' => 'http://cdn.example.test/image.jpg'], ['headline' => 'News']))
        ->toMatchArray(['image' => 'http://cdn.example.test/image.jpg']);
});

it('renders configured site identity only when enabled and combines graph nodes without duplicate types', function (): void {
    config()->set('filament-seo.structured_data.site', [
        'enabled' => true,
        'url' => 'https://example.test/',
        'organization' => ['name' => 'Example Co', 'description' => null, 'logo' => null, 'same_as' => []],
        'website' => ['name' => 'Example', 'description' => null],
    ]);

    $article = Article::query()->create(['name' => 'Home']);
    $article->seoMetadata()->create([
        'meta_title' => 'Example homepage', 'canonical_url' => 'https://example.test/',
        'structured_data_enabled' => true, 'structured_data_type' => 'WebSite',
        'structured_data' => ['name' => 'Example', 'url' => 'https://example.test/'],
    ]);

    $payload = jsonLdPayload(renderJsonLd($article->fresh()));

    expect($payload['@context'])->toBe('https://schema.org')
        ->and(collect($payload['@graph'])->pluck('@type')->all())->toBe(['WebSite', 'Organization'])
        ->and($payload['@graph'][0]['publisher'])->toBe(['@id' => 'https://example.test#organization']);
});

it('does not write during rendering and suppresses duplicate output for the same owner in one request', function (): void {
    $article = Article::query()->create(['name' => 'No writes']);
    $article->seoMetadata()->create(['meta_title' => 'No writes', 'structured_data_enabled' => true, 'structured_data_type' => 'WebPage']);
    $record = $article->fresh();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $html = Blade::render('<x-filament-seo::json-ld :record="$record" /><x-filament-seo::json-ld :record="$record" />', ['record' => $record]);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(substr_count($html, 'application/ld+json'))->toBe(1)
        ->and(collect($queries)->filter(fn (array $query): bool => preg_match('/\b(insert|update|delete)\b/i', $query['query']) === 1))->toBeEmpty();
});

it('supports explicit model attribute mappings without loading relations or inferring dates', function (): void {
    config()->set('filament-seo.structured_data.model_mappings.'.Article::class, [
        'type' => 'NewsArticle', 'fields' => ['headline' => 'name', 'datePublished' => 'published_at'],
    ]);
    $article = Article::query()->create(['name' => 'Mapped title']);
    $article->setAttribute('published_at', '2026-06-05T12:00:00+00:00');
    $article->seoMetadata()->create(['structured_data_enabled' => true, 'structured_data_type' => 'NewsArticle']);

    expect(jsonLdPayload(renderJsonLd($article)))->toMatchArray([
        '@type' => 'NewsArticle', 'headline' => 'Mapped title', 'datePublished' => '2026-06-05T12:00:00+00:00',
    ]);
});

it('keeps existing metadata rows valid when the additive migration is rolled back and reapplied', function (): void {
    $article = Article::query()->create(['name' => 'Legacy row']);
    $metadata = $article->seoMetadata()->create(['meta_title' => 'Legacy title']);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_19_100000_add_structured_data_to_seo_metadata_table.php';

    $migration->down();
    expect(DB::table('seo_metadata')->where('id', $metadata->getKey())->value('meta_title'))->toBe('Legacy title');
    $migration->up();

    expect($metadata->fresh()->structured_data_enabled)->toBeFalse()
        ->and($metadata->fresh()->structured_data)->toBeNull();
});
