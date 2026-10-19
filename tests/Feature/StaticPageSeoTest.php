<?php

use Digit7s\FilamentSeo\Filament\Resources\Pages\CreateSeoPage;
use Digit7s\FilamentSeo\Filament\Resources\Pages\EditSeoPage;
use Digit7s\FilamentSeo\Filament\Resources\Pages\ListSeoPages;
use Digit7s\FilamentSeo\Filament\Resources\Pages\ViewSeoPage;
use Digit7s\FilamentSeo\Filament\Resources\SeoPageResource;
use Digit7s\FilamentSeo\Models\SeoMetadata;
use Digit7s\FilamentSeo\Models\SeoPage;
use Digit7s\FilamentSeo\Support\SeoCompletenessAnalyzer;
use Digit7s\FilamentSeo\Support\SeoMetadataResolver;
use Digit7s\FilamentSeo\Support\SeoPagePath;
use Digit7s\FilamentSeo\Support\SeoScoreCalculator;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\Article;
use Digit7s\FilamentSeo\Tests\Support\CreatesSeoSchema;
use Digit7s\FilamentSeo\View\Components\Meta;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as DatabaseSchema;

uses(CreatesSeoSchema::class);

beforeEach(function (): void {
    $this->createSeoSchema();
    config()->set('filament-seo.defaults', ['title' => null, 'description' => null, 'image' => null]);
    config()->set('filament-seo.fallbacks', ['default' => [], 'models' => []]);
    config()->set('filament-seo.images.disk', null);
    config()->set('filament-seo.images.public_disks', []);
    config()->set('filament-seo.images.url_resolver', null);
    config()->set('filament-seo.static_pages.resolve_current_request', false);
    config()->set('filament-seo.static_pages.navigation', [
        'group' => 'SEO',
        'label' => 'Static Pages',
        'icon' => 'heroicon-o-globe-alt',
        'sort' => 100,
        'enabled' => true,
    ]);
});

function staticSeoPage(array $attributes = []): SeoPage
{
    return SeoPage::query()->create(array_merge([
        'name' => 'About Us',
        'page_key' => 'about-us',
        'route_name' => null,
        'path' => '/about-us',
        'is_active' => true,
    ], $attributes));
}

it('creates stable static page identities and normalizes exact paths', function (): void {
    $root = staticSeoPage(['name' => 'Home', 'page_key' => 'home', 'path' => '////?campaign=x#top']);
    $about = staticSeoPage(['path' => 'about//us///?tab=team#staff']);

    expect($root->path)->toBe('/')
        ->and($about->path)->toBe('/about/us')
        ->and($about->route_name)->toBeNull()
        ->and(SeoPagePath::normalize(''))->toBe('/')
        ->and(SeoPagePath::normalize('/a//b/?x=1#fragment'))->toBe('/a/b')
        ->and(fn () => SeoPagePath::normalize('https://example.test/about'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => staticSeoPage(['page_key' => 'About_Us']))
        ->toThrow(InvalidArgumentException::class);
});

it('enforces unique page keys, paths, and non-null route names', function (): void {
    staticSeoPage(['route_name' => 'about']);

    expect(fn () => staticSeoPage(['path' => '/elsewhere', 'page_key' => 'about-us']))
        ->toThrow(QueryException::class)
        ->and(fn () => staticSeoPage(['page_key' => 'different-key']))
        ->toThrow(QueryException::class);

    // A duplicate route name conflicts even if the page path differs.
    expect(fn () => staticSeoPage(['page_key' => 'other', 'path' => '/other', 'route_name' => 'about']))
        ->toThrow(QueryException::class);
});

it('reuses the polymorphic metadata row and removes it with its registered page', function (): void {
    $page = staticSeoPage();
    $page->seoMetadata()->create(['meta_title' => 'About SEO title', 'robots_index' => false, 'robots_follow' => true]);

    expect($page->fresh()->seoMetadata)->toBeInstanceOf(SeoMetadata::class)
        ->and(SeoMetadata::query()->first()->seoable)->toBeInstanceOf(SeoPage::class);

    $page->delete();

    expect(SeoPage::query()->count())->toBe(0)->and(SeoMetadata::query()->count())->toBe(0);
});

it('updates a page identity and its existing metadata without creating a duplicate row', function (): void {
    $page = staticSeoPage();
    $metadata = $page->seoMetadata()->create(['meta_title' => 'Old title']);

    $page->update(['name' => 'About', 'path' => '///company/about///?source=nav']);
    $metadata->update(['meta_title' => 'Updated title']);

    expect($page->fresh()->path)->toBe('/company/about')
        ->and($page->fresh()->seoMetadata->meta_title)->toBe('Updated title')
        ->and(SeoMetadata::query()->count())->toBe(1);
});

it('keeps existing metadata rows intact when the new registry migration is applied', function (): void {
    $article = Article::query()->create(['name' => 'Legacy article']);
    $article->seoMetadata()->create(['meta_title' => 'Existing metadata']);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_19_000000_create_seo_pages_table.php';

    $migration->down();
    $migration->up();

    expect(SeoMetadata::query()->where('meta_title', 'Existing metadata')->exists())->toBeTrue()
        ->and(DatabaseSchema::hasTable('seo_pages'))->toBeTrue();
});

it('renders metadata for an explicit page key using the existing resolver and Blade view', function (): void {
    $page = staticSeoPage();
    $page->seoMetadata()->create([
        'meta_title' => '<script>safe escaped</script>',
        'meta_description' => 'Static page description',
        'canonical_url' => 'javascript:alert(1)',
        'og_image' => 'javascript:alert(1)',
        'robots_index' => false,
        'robots_follow' => false,
    ]);

    $html = Blade::render('<x-filament-seo::meta page="about-us" />');

    expect($html)->toContain('&lt;script&gt;safe escaped&lt;/script&gt;')
        ->and($html)->toContain('name="description" content="Static page description"')
        ->and($html)->toContain('content="noindex, nofollow"')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('rel="canonical"')
        ->and($html)->not->toContain('property="og:image"');
});

it('supports explicit named routes and exact current route or path matching when enabled', function (): void {
    $page = staticSeoPage(['route_name' => 'about']);
    $page->seoMetadata()->create(['meta_title' => 'Named route title']);

    expect(Blade::render('<x-filament-seo::meta route="about" />'))->toContain('<title>Named route title</title>');

    Route::get('/about-us', static fn () => 'about')->name('frontend.about');
    $this->get('/about-us')->assertOk();
    config()->set('filament-seo.static_pages.resolve_current_request', true);

    expect(Blade::render('<x-filament-seo::meta />'))->toContain('<title>Named route title</title>');
});

it('does not fall through an inactive named route to a different page sharing its path', function (): void {
    staticSeoPage(['page_key' => 'public-path', 'path' => '/other-path'])->seoMetadata()->create(['meta_title' => 'Wrong page']);
    staticSeoPage(['page_key' => 'inactive-route', 'path' => '/same-path', 'route_name' => 'frontend.same', 'is_active' => false])
        ->seoMetadata()->create(['meta_title' => 'Inactive route title']);
    Route::get('/same-path', static fn () => 'same')->name('frontend.same');
    $this->get('/same-path')->assertOk();
    config()->set('filament-seo.static_pages.resolve_current_request', true);

    expect(Blade::render('<x-filament-seo::meta />'))->toBe('');
});

it('does not resolve inactive or missing pages or borrow defaults from another page', function (): void {
    config()->set('filament-seo.defaults.title', 'Global fallback title');
    staticSeoPage(['page_key' => 'inactive', 'is_active' => false])->seoMetadata()->create(['meta_title' => 'Hidden title']);
    $emptyPage = staticSeoPage(['page_key' => 'empty', 'path' => '/empty']);
    $metadataCount = SeoMetadata::query()->count();

    expect(Blade::render('<x-filament-seo::meta page="inactive" />'))->toBe('')
        ->and(Blade::render('<x-filament-seo::meta page="not-registered" />'))->toBe('')
        ->and(Blade::render('<x-filament-seo::meta page="empty" />'))->toContain('<title>Global fallback title</title>')
        ->and(SeoPage::query()->count())->toBe(2)
        ->and(SeoMetadata::query()->count())->toBe($metadataCount)
        ->and($emptyPage->fresh()->seoMetadata)->toBeNull();
});

it('rejects conflicting selectors and preserves existing record rendering', function (): void {
    $article = Article::query()->create(['name' => 'Article']);
    $article->seoMetadata()->create(['meta_title' => 'Article metadata']);

    expect(Blade::render('<x-filament-seo::meta :record="$article" />', ['article' => $article]))
        ->toContain('<title>Article metadata</title>')
        ->and(fn () => new Meta($article, page: 'about-us'))
        ->toThrow(InvalidArgumentException::class);
});

it('uses shared completeness and score calculations for static pages', function (): void {
    $page = staticSeoPage();
    $metadata = $page->seoMetadata()->create([
        'meta_title' => str_repeat('T', 55),
        'meta_description' => str_repeat('D', 155),
        'canonical_url' => 'https://example.test/about-us',
        'seo_image' => 'https://cdn.example.test/about.jpg',
        'robots_index' => true,
        'robots_follow' => true,
    ]);
    $resolved = app(SeoMetadataResolver::class)->resolve($page, $metadata);

    expect(app(SeoCompletenessAnalyzer::class)->analyze($metadata, $resolved)->status)->toBe('complete')
        ->and(app(SeoScoreCalculator::class)->calculate($metadata, $resolved)->score)->toBe(100);
});

it('exposes an explicit Filament 5 resource with native CRUD pages and shared form fields', function (): void {
    $schema = SeoPageResource::form(Schema::make());
    $components = $schema->getComponents();
    $seoSection = $components[1];

    expect(SeoPageResource::getModel())->toBe(SeoPage::class)
        ->and(SeoPageResource::getPages())->toHaveKeys(['index', 'create', 'view', 'edit'])
        ->and(SeoPageResource::getEloquentQuery()->getEagerLoads())->toHaveKey('seoMetadata')
        ->and($seoSection)->toBeInstanceOf(Section::class)
        ->and($seoSection->getRelationshipName())->toBe('seoMetadata')
        ->and(ListSeoPages::class)->toBeString()
        ->and(CreateSeoPage::class)->toBeString()
        ->and(EditSeoPage::class)->toBeString()
        ->and(ViewSeoPage::class)->toBeString();
});

it('uses configurable Filament navigation defaults without publishing package configuration', function (): void {
    expect(SeoPageResource::getNavigationGroup())->toBe('SEO')
        ->and(SeoPageResource::getNavigationLabel())->toBe('Static Pages')
        ->and(SeoPageResource::getNavigationIcon())->toBe('heroicon-o-globe-alt')
        ->and(SeoPageResource::getNavigationSort())->toBe(100)
        ->and(SeoPageResource::shouldRegisterNavigation())->toBeTrue();
});

it('uses custom static page navigation values and permits an ungrouped item', function (): void {
    config()->set('filament-seo.static_pages.navigation', [
        'group' => 'Content Management',
        'label' => 'SEO Pages',
        'icon' => 'heroicon-o-document-text',
        'sort' => 30,
        'enabled' => true,
    ]);

    expect(SeoPageResource::getNavigationGroup())->toBe('Content Management')
        ->and(SeoPageResource::getNavigationLabel())->toBe('SEO Pages')
        ->and(SeoPageResource::getNavigationIcon())->toBe('heroicon-o-document-text')
        ->and(SeoPageResource::getNavigationSort())->toBe(30)
        ->and(SeoPageResource::shouldRegisterNavigation())->toBeTrue();

    config()->set('filament-seo.static_pages.navigation.group', null);

    expect(SeoPageResource::getNavigationGroup())->toBeNull();

    config()->set('filament-seo.static_pages.navigation.sort', null);

    expect(SeoPageResource::getNavigationSort())->toBeNull();
});

it('hides navigation when configured without changing the authorization result for routes', function (): void {
    app()->register(FilamentServiceProvider::class);
    $panel = Panel::make()->id('test')->default();
    Filament::setCurrentPanel($panel);
    Auth::guard('web')->setUser(new GenericUser(['id' => 1]));
    Gate::define('viewAny', fn (GenericUser $user): bool => $user->id === 1);
    config()->set('filament-seo.static_pages.navigation.enabled', false);

    SeoPageResource::registerNavigationItems();

    expect(SeoPageResource::shouldRegisterNavigation())->toBeFalse()
        ->and($panel->getNavigationItems())->toBeEmpty()
        ->and(SeoPageResource::canAccess())->toBeTrue()
        ->and(SeoPageResource::canViewAny())->toBeTrue()
        ->and(SeoPageResource::getPages())->toHaveKeys(['index', 'create', 'view', 'edit']);
});

it('denies authenticated panel users when no SEO page authorization rule is defined', function (): void {
    app()->register(FilamentServiceProvider::class);
    $panel = Panel::make()->id('test')->default();
    Filament::setCurrentPanel($panel);
    Auth::guard('web')->setUser(new GenericUser(['id' => 1]));

    SeoPageResource::registerNavigationItems();

    expect($panel->getNavigationItems())->toBeEmpty()
        ->and(SeoPageResource::canAccess())->toBeFalse()
        ->and(SeoPageResource::canViewAny())->toBeFalse()
        ->and(SeoPageResource::canCreate())->toBeFalse();
});

it('allows SEO page access when the host application defines the matching Gate ability', function (): void {
    app()->register(FilamentServiceProvider::class);
    $panel = Panel::make()->id('test')->default();
    Filament::setCurrentPanel($panel);
    Auth::guard('web')->setUser(new GenericUser(['id' => 1]));
    Gate::define('viewAny', fn (GenericUser $user): bool => $user->id === 1);
    Route::get('/test/seo-pages', static fn (): string => '')->name('filament.test.resources.seo-pages.index');

    SeoPageResource::registerNavigationItems();
    $item = $panel->getNavigationItems()[0];

    expect($panel->getNavigationItems())->toHaveCount(1)
        ->and($item->getGroup())->toBe('SEO')
        ->and($item->getLabel())->toBe('Static Pages')
        ->and($item->getIcon())->toBe('heroicon-o-globe-alt')
        ->and($item->getSort())->toBe(100)
        ->and(SeoPageResource::canAccess())->toBeTrue()
        ->and(SeoPageResource::canViewAny())->toBeTrue();

    Auth::guard('web')->setUser(new GenericUser(['id' => 2]));

    expect(SeoPageResource::canAccess())->toBeFalse()
        ->and(SeoPageResource::canViewAny())->toBeFalse()
        ->and(SeoPageResource::canCreate())->toBeFalse();
});
