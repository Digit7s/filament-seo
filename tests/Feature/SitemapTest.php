<?php

use Digit7s\FilamentSeo\Models\SeoMetadata;
use Digit7s\FilamentSeo\Models\SeoPage;
use Digit7s\FilamentSeo\Sitemap\SitemapCache;
use Digit7s\FilamentSeo\Sitemap\SitemapDate;
use Digit7s\FilamentSeo\Sitemap\SitemapEntry;
use Digit7s\FilamentSeo\Sitemap\SitemapGenerator;
use Digit7s\FilamentSeo\Sitemap\SitemapRouteRegistrar;
use Digit7s\FilamentSeo\Sitemap\SitemapUrlPolicy;
use Digit7s\FilamentSeo\Sitemap\SitemapXmlWriter;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\SitemapArticle;
use Digit7s\FilamentSeo\Tests\Fixtures\Sitemap\PublishedOnlySitemapQuery;
use Digit7s\FilamentSeo\Tests\Fixtures\Sitemap\SyntheticSitemapSource;
use Digit7s\FilamentSeo\Tests\Support\CreatesSeoSchema;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(CreatesSeoSchema::class);

beforeEach(function (): void {
    $this->createSeoSchema();
    config()->set('app.url', 'https://example.test');
    config()->set('filament-seo.sitemap', [
        'enabled' => false,
        'path' => 'sitemap.xml',
        'static_pages' => ['enabled' => true, 'filter' => null],
        'models' => [],
        'sources' => [],
        'chunk_size' => 10000,
        'model_chunk_size' => 500,
        'cache' => ['enabled' => false, 'ttl' => 3600],
    ]);
});

function sitemapPage(array $values = []): SeoPage
{
    static $next = 0;
    $number = ++$next;

    return SeoPage::query()->create($values + [
        'name' => 'Page '.$number,
        'page_key' => 'page-'.$number,
        'path' => '/page-'.$number,
        'is_active' => true,
    ]);
}

function parseSitemap(string $xml): SimpleXMLElement
{
    return new SimpleXMLElement($xml);
}

it('does not register public routes unless explicitly enabled and validates route paths', function (): void {
    expect(Route::has('filament-seo.sitemap.index'))->toBeFalse();

    config()->set('filament-seo.sitemap.enabled', true);
    config()->set('filament-seo.sitemap.path', '../private.xml');
    expect(fn () => SitemapRouteRegistrar::register())->toThrow(InvalidArgumentException::class);

    config()->set('filament-seo.sitemap.path', 'seo/sitemap.xml');
    SitemapRouteRegistrar::register();
    SitemapRouteRegistrar::register();
    expect(Route::has('filament-seo.sitemap.index'))->toBeTrue()
        ->and(Route::has('filament-seo.sitemap.chunk'))->toBeTrue()
        ->and(Route::getRoutes()->getByName('filament-seo.sitemap.index')->getActionName())->not->toContain('Closure');
});

it('rejects sitemap route collisions instead of silently replacing consumer routes', function (): void {
    Route::get('/sitemap.xml', static fn () => 'consumer sitemap')->name('consumer.sitemap');
    config()->set('filament-seo.sitemap.enabled', true);

    expect(fn () => SitemapRouteRegistrar::register())->toThrow(LogicException::class);
});

it('includes active indexable static pages and excludes inactive, noindex, cross-domain, and duplicate canonical URLs', function (): void {
    $about = sitemapPage(['name' => 'About', 'page_key' => 'about', 'path' => '/about/']);
    $about->seoMetadata()->create(['canonical_url' => 'https://example.test/about', 'robots_index' => true]);
    sitemapPage(['name' => 'Duplicate', 'page_key' => 'duplicate', 'path' => '/duplicate'])
        ->seoMetadata()->create(['canonical_url' => 'https://example.test/about']);
    sitemapPage(['name' => 'Private', 'page_key' => 'private', 'path' => '/private'])
        ->seoMetadata()->create(['robots_index' => false]);
    sitemapPage(['name' => 'External canonical', 'page_key' => 'external', 'path' => '/external'])
        ->seoMetadata()->create(['canonical_url' => 'https://elsewhere.test/page']);
    sitemapPage(['name' => 'Nofollow only', 'page_key' => 'nofollow-only', 'path' => '/nofollow-only'])
        ->seoMetadata()->create(['robots_index' => true, 'robots_follow' => false]);
    sitemapPage(['name' => 'Inactive', 'page_key' => 'inactive', 'path' => '/inactive', 'is_active' => false]);
    sitemapPage(['name' => 'No metadata', 'page_key' => 'no-metadata', 'path' => '/no-metadata']);

    $documents = iterator_to_array(app(SitemapGenerator::class)->documents());
    $xml = parseSitemap($documents[0]['xml']);
    $xml->registerXPathNamespace('s', SitemapXmlWriter::NAMESPACE);
    $locations = array_map('strval', $xml->xpath('//s:url/s:loc'));

    expect($locations)->toBe([
        'https://example.test/about',
        'https://example.test/nofollow-only',
        'https://example.test/no-metadata',
    ]);
    expect(SeoMetadata::query()->count())->toBe(5);
});

it('returns a valid empty URL set when every source is disabled', function (): void {
    config()->set('filament-seo.sitemap.static_pages.enabled', false);
    $documents = iterator_to_array(app(SitemapGenerator::class)->documents());
    $xml = parseSitemap($documents[0]['xml']);
    $xml->registerXPathNamespace('s', SitemapXmlWriter::NAMESPACE);

    expect($documents)->toHaveCount(1)
        ->and($xml->getName())->toBe('urlset')
        ->and($xml->xpath('//s:url'))->toBe([]);
});

it('iterates explicitly configured UUID models in stable order, applies host publishing rules, and deduplicates canonicals', function (): void {
    Route::get('/news/{news:slug}', static fn () => 'news')->name('news.show');
    Route::getRoutes()->refreshNameLookups();
    SitemapArticle::query()->create(['id' => 'f-uuid', 'slug' => 'z-last', 'status' => 'published']);
    SitemapArticle::query()->create(['id' => 'a-uuid', 'slug' => 'a-first', 'status' => 'published']);
    SitemapArticle::query()->create(['id' => 'd-uuid', 'slug' => 'duplicate', 'status' => 'published']);
    SitemapArticle::query()->create(['id' => 'b-uuid', 'slug' => 'draft', 'status' => 'draft']);
    SitemapArticle::query()->create(['id' => 'c-uuid', 'slug' => 'deleted', 'status' => 'published'])->delete();
    SitemapArticle::query()->whereKey('f-uuid')->first()->seoMetadata()->create(['robots_index' => false]);
    SitemapArticle::query()->whereKey('d-uuid')->first()->seoMetadata()->create(['canonical_url' => 'https://example.test/shared']);
    sitemapPage(['page_key' => 'shared', 'path' => '/shared'])->seoMetadata()->create([]);

    config()->set('filament-seo.sitemap.models', [SitemapArticle::class => [
        'enabled' => true,
        'route' => 'news.show',
        'route_key' => 'slug',
        'route_parameter' => 'news',
        'query' => PublishedOnlySitemapQuery::class,
        'lastmod' => null,
    ]]);

    $queries = [];
    DB::listen(static function (QueryExecuted $event) use (&$queries): void {
        if (str_contains(strtolower($event->sql), 'seo_metadata')) {
            $queries[] = $event->sql;
        }
    });

    $documents = iterator_to_array(app(SitemapGenerator::class)->documents());
    $xml = parseSitemap($documents[0]['xml']);
    $xml->registerXPathNamespace('s', SitemapXmlWriter::NAMESPACE);
    $locations = array_map('strval', $xml->xpath('//s:url/s:loc'));

    expect($locations)->toBe(['https://example.test/shared', 'https://example.test/news/a-first'])
        ->and(count($queries))->toBeLessThanOrEqual(2);
});

it('uses custom URL callbacks and only emits explicitly selected meaningful lastmod values', function (): void {
    Route::get('/news/{news}', static fn () => 'news')->name('news.key');
    Route::getRoutes()->refreshNameLookups();
    $article = SitemapArticle::query()->create([
        'id' => 'uuid-one',
        'slug' => 'article-one',
        'status' => 'published',
        'published_at' => '2026-10-19 12:00:00',
    ]);
    config()->set('filament-seo.sitemap.models', [SitemapArticle::class => [
        'url' => static fn (SitemapArticle $record) => '/stories/'.$record->slug,
        'lastmod' => static fn (SitemapArticle $record) => $record->published_at,
        'query' => static fn ($query) => $query->where('status', 'published'),
    ]]);

    $document = iterator_to_array(app(SitemapGenerator::class)->documents())[0]['xml'];
    $xml = parseSitemap($document);
    $xml->registerXPathNamespace('s', SitemapXmlWriter::NAMESPACE);

    expect((string) $xml->xpath('//s:url/s:loc')[0])->toBe('https://example.test/stories/article-one')
        ->and((string) $xml->xpath('//s:url/s:lastmod')[0])->toBe('2026-10-19T12:00:00+00:00');
});

it('omits fabricated or malformed lastmod values and preserves valid W3C dates', function (): void {
    config()->set('app.timezone', 'Asia/Bangkok');

    $xml = app(SitemapXmlWriter::class)->urlSet([
        new SitemapEntry('https://example.test/relative', 'yesterday'),
        new SitemapEntry('https://example.test/malformed', '2026-02-30T12:00:00Z'),
        new SitemapEntry('https://example.test/date', '2026-10-19'),
        new SitemapEntry('https://example.test/timestamp', '2026-10-19T12:00:00Z'),
    ]);
    $parsed = parseSitemap($xml);
    $parsed->registerXPathNamespace('s', SitemapXmlWriter::NAMESPACE);
    $lastmod = array_map('strval', $parsed->xpath('//s:url/s:lastmod'));

    expect($lastmod)->toBe(['2026-10-19', '2026-10-19T12:00:00+00:00'])
        ->and(SitemapDate::format('tomorrow'))->toBeNull()
        ->and(SitemapDate::format('2026-02-30'))->toBeNull();
});

it('creates deterministic chunked XML and an index that references the registered chunk endpoints', function (): void {
    sitemapPage(['page_key' => 'one', 'path' => '/one']);
    sitemapPage(['page_key' => 'two', 'path' => '/two']);
    sitemapPage(['page_key' => 'three', 'path' => '/three']);
    config()->set('filament-seo.sitemap.enabled', true);
    config()->set('filament-seo.sitemap.chunk_size', 2);
    SitemapRouteRegistrar::register();

    $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $index = parseSitemap($response->getContent());
    $index->registerXPathNamespace('s', SitemapXmlWriter::NAMESPACE);
    $indexUrls = array_map('strval', $index->xpath('//s:sitemap/s:loc'));
    expect($indexUrls)->toBe(['https://example.test/sitemap-1.xml', 'https://example.test/sitemap-2.xml']);

    $first = $this->get('/sitemap-1.xml')->assertOk()->getContent();
    $second = $this->get('/sitemap-2.xml')->assertOk()->getContent();
    $this->get('/sitemap-3.xml')->assertNotFound();
    $firstXml = parseSitemap($first);
    $firstXml->registerXPathNamespace('s', SitemapXmlWriter::NAMESPACE);
    $secondXml = parseSitemap($second);
    $secondXml->registerXPathNamespace('s', SitemapXmlWriter::NAMESPACE);
    expect($firstXml->xpath('//s:url/s:loc'))->toHaveCount(2)
        ->and($secondXml->xpath('//s:url/s:loc'))->toHaveCount(1);
});

it('splits at the 50,000 URL protocol boundary with bounded source iteration', function (): void {
    config()->set('filament-seo.sitemap.static_pages.enabled', false);
    config()->set('filament-seo.sitemap.sources', [SyntheticSitemapSource::class]);
    config()->set('filament-seo.sitemap.synthetic_count', 50000);
    config()->set('filament-seo.sitemap.chunk_size', 50000);

    $memoryBefore = memory_get_usage(false);
    $documents = iterator_to_array(app(SitemapGenerator::class)->documents());
    $retainedMemory = memory_get_usage(false) - $memoryBefore;
    expect($documents)->toHaveCount(1)
        ->and($documents[0]['number'])->toBe(1)
        ->and($retainedMemory)->toBeLessThan(64 * 1024 * 1024);

    $first = parseSitemap($documents[0]['xml']);
    $first->registerXPathNamespace('s', SitemapXmlWriter::NAMESPACE);
    expect($first->xpath('//s:url'))->toHaveCount(50000)
        ->and(strlen($documents[0]['xml']))->toBeLessThanOrEqual(SitemapGenerator::TARGET_DOCUMENT_BYTES);
});

it('fails predictably above the total URL memory safety ceiling', function (): void {
    config()->set('filament-seo.sitemap.static_pages.enabled', false);
    config()->set('filament-seo.sitemap.sources', [SyntheticSitemapSource::class]);
    config()->set('filament-seo.sitemap.synthetic_count', SitemapGenerator::MAX_TOTAL_URLS + 1);
    config()->set('filament-seo.sitemap.chunk_size', 10000);

    expect(fn () => iterator_to_array(app(SitemapGenerator::class)->documents()))
        ->toThrow(InvalidArgumentException::class, '50,000 unique URLs');
});

it('splits earlier at the conservative uncompressed document target', function (): void {
    config()->set('filament-seo.sitemap.static_pages.enabled', false);
    config()->set('filament-seo.sitemap.sources', [SyntheticSitemapSource::class]);
    config()->set('filament-seo.sitemap.synthetic_count', 5000);
    config()->set('filament-seo.sitemap.synthetic_length', 1800);
    config()->set('filament-seo.sitemap.chunk_size', 50000);

    $documents = iterator_to_array(app(SitemapGenerator::class)->documents());
    expect($documents)->toHaveCount(2)
        ->and(max(array_map(static fn ($document) => strlen($document['xml']), $documents)))
        ->toBeLessThanOrEqual(SitemapGenerator::TARGET_DOCUMENT_BYTES);
});

it('rejects XML documents above the conservative memory output target', function (): void {
    $entries = (static function (): iterable {
        for ($number = 0; $number < 30000; $number++) {
            yield new SitemapEntry('https://example.test/'.str_repeat('x', 1900).$number);
        }
    })();

    expect(fn () => app(SitemapXmlWriter::class)->urlSet($entries))
        ->toThrow(InvalidArgumentException::class, '8 MB');
});

it('bounds sitemap index XML before accumulating a large uncompressed document', function (): void {
    $locations = array_fill(0, 5000, 'https://example.test/'.str_repeat('x', 1900));

    expect(fn () => app(SitemapXmlWriter::class)->sitemapIndex($locations))
        ->toThrow(InvalidArgumentException::class, '8 MB');
});

it('keeps generated origins independent of request hosts and emits UTF-8 and escaped XML safely', function (): void {
    $page = sitemapPage(['name' => 'Café & Team', 'page_key' => 'cafe', 'path' => '/café']);
    $page->seoMetadata()->create(['canonical_url' => 'https://example.test/café?team=A&B']);
    config()->set('filament-seo.sitemap.enabled', true);
    SitemapRouteRegistrar::register();

    $response = $this->withServerVariables(['HTTP_HOST' => 'attacker.test'])->get('/sitemap.xml')->assertOk();
    expect($response->getContent())->toContain('encoding="UTF-8"')
        ->and($response->getContent())->toContain('https://example.test/caf%C3%A9?team=A&amp;B')
        ->and($response->getContent())->not->toContain('attacker.test');

    $xml = parseSitemap($response->getContent());
    $xml->registerXPathNamespace('s', SitemapXmlWriter::NAMESPACE);
    expect((string) $xml->xpath('//s:url/s:loc')[0])->toBe('https://example.test/caf%C3%A9?team=A&B');
});

it('returns a safe service-unavailable response for invalid configuration without leaking exception details', function (): void {
    sitemapPage();
    config()->set('filament-seo.sitemap.enabled', true);
    config()->set('filament-seo.sitemap.chunk_size', 0);
    SitemapRouteRegistrar::register();

    $this->get('/sitemap.xml')->assertStatus(503)->assertSeeText('Sitemap temporarily unavailable.')
        ->assertDontSee('chunk_size');
});

it('caches the index and chunk snapshot and supports explicit invalidation', function (): void {
    sitemapPage(['page_key' => 'cached', 'path' => '/cached']);
    config()->set('cache.default', 'array');
    config()->set('filament-seo.sitemap.enabled', true);
    config()->set('filament-seo.sitemap.cache.enabled', true);
    SitemapRouteRegistrar::register();

    $first = $this->get('/sitemap.xml')->assertOk()->getContent();
    sitemapPage(['page_key' => 'fresh', 'path' => '/fresh']);
    $cached = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($cached)->toBe($first)->not->toContain('/fresh');

    app(SitemapCache::class)->clear();
    $fresh = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($fresh)->toContain('/fresh');
});

it('keeps host data changes stale only until the configured sitemap cache TTL expires', function (): void {
    $firstPage = sitemapPage(['page_key' => 'ttl-first', 'path' => '/ttl-first']);
    $firstPage->seoMetadata()->create(['robots_index' => true]);
    config()->set('cache.default', 'array');
    config()->set('filament-seo.sitemap.enabled', true);
    config()->set('filament-seo.sitemap.cache', ['enabled' => true, 'ttl' => 60]);
    SitemapRouteRegistrar::register();

    $initial = $this->get('/sitemap.xml')->assertOk()->getContent();
    sitemapPage(['page_key' => 'ttl-later', 'path' => '/ttl-later']);
    $cached = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($cached)->toBe($initial)->not->toContain('/ttl-later');

    $firstPage->seoMetadata()->firstOrFail()->update(['robots_index' => false]);
    expect($this->get('/sitemap.xml')->assertOk()->getContent())->toBe($initial);

    $this->travel(61)->seconds();
    $expired = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($expired)->toContain('/ttl-later')->not->toContain('/ttl-first');
});

it('isolates cached output when the trusted sitemap source configuration changes', function (): void {
    sitemapPage(['page_key' => 'config-first', 'path' => '/config-first']);
    sitemapPage(['page_key' => 'config-second', 'path' => '/config-second']);
    config()->set('cache.default', 'array');
    config()->set('filament-seo.sitemap.enabled', true);
    config()->set('filament-seo.sitemap.chunk_size', 10);
    config()->set('filament-seo.sitemap.cache', ['enabled' => true, 'ttl' => 3600]);
    SitemapRouteRegistrar::register();

    $initial = $this->get('/sitemap.xml')->assertOk();
    expect(parseSitemap($initial->getContent())->getName())->toBe('urlset');

    config()->set('filament-seo.sitemap.chunk_size', 1);
    $changed = parseSitemap($this->get('/sitemap.xml')->assertOk()->getContent());
    $changed->registerXPathNamespace('s', SitemapXmlWriter::NAMESPACE);

    expect($changed->getName())->toBe('sitemapindex')
        ->and($changed->xpath('//s:sitemap/s:loc'))->toHaveCount(2);
});

it('isolates cached documents by a trusted request-scoped content key', function (): void {
    sitemapPage(['page_key' => 'tenant-one-home', 'path' => '/tenant-one']);
    sitemapPage(['page_key' => 'tenant-two-home', 'path' => '/tenant-two']);
    $tenant = 'one';
    config()->set('cache.default', 'array');
    config()->set('filament-seo.sitemap.enabled', true);
    config()->set('filament-seo.sitemap.static_pages.filter', static function (SeoPage $page) use (&$tenant): bool {
        return str_starts_with($page->page_key, 'tenant-'.$tenant);
    });
    config()->set('filament-seo.sitemap.cache', [
        'enabled' => true,
        'ttl' => 3600,
        'scope_resolver' => static function () use (&$tenant): string {
            return $tenant;
        },
    ]);
    SitemapRouteRegistrar::register();

    $one = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($one)->toContain('/tenant-one')->not->toContain('/tenant-two');

    $tenant = 'two';
    $two = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($two)->toContain('/tenant-two')->not->toContain('/tenant-one');

    $tenant = 'one';
    expect($this->get('/sitemap.xml')->assertOk()->getContent())->toBe($one);
});

it('normalizes URLs, rejects unsafe schemes, traversal, foreign origins, and malformed config origins', function (): void {
    $policy = app(SitemapUrlPolicy::class);
    expect($policy->fromPath('/a//b/'))->toBe('https://example.test/a/b')
        ->and($policy->fromPath('/%2e%2e/private'))->toBeNull()
        ->and($policy->fromCandidate('javascript:alert(1)'))->toBeNull()
        ->and($policy->fromCandidate('https://outside.test/page'))->toBeNull()
        ->and($policy->fromCandidate('https://example.test/a#fragment'))->toBe('https://example.test/a');

    config()->set('app.url', 'file:///tmp/site');
    expect(fn () => app(SitemapUrlPolicy::class))->toThrow(InvalidArgumentException::class);
});
