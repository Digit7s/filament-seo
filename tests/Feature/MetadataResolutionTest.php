<?php

use Digit7s\FilamentSeo\Data\ResolvedSeoMetadata;
use Digit7s\FilamentSeo\Data\SeoCompleteness;
use Digit7s\FilamentSeo\Models\SeoMetadata;
use Digit7s\FilamentSeo\Support\SeoCompletenessAnalyzer;
use Digit7s\FilamentSeo\Support\SeoMetadataResolver;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\Article;
use Digit7s\FilamentSeo\Tests\Support\CreatesSeoSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(CreatesSeoSchema::class);

beforeEach(function () {
    $this->createSeoSchema();
    config()->set('filament-seo.fallbacks', ['default' => [], 'models' => []]);
    config()->set('filament-seo.defaults', ['title' => null, 'description' => null, 'image' => null]);
    config()->set('filament-seo.images.disk', null);
    config()->set('filament-seo.images.public_disks', []);
    config()->set('filament-seo.images.url_resolver', null);
    config()->set('filament-seo.images.allow_http', false);
});

function articleWithName(string $name = 'Owner'): Article
{
    return Article::query()->create(['name' => $name]);
}

function seoMetadata(array $attributes = []): SeoMetadata
{
    return new SeoMetadata(array_merge([
        'robots_index' => true,
        'robots_follow' => true,
    ], $attributes));
}

it('resolves explicit SEO values before configured owner and site fallbacks', function () {
    $owner = articleWithName(' Owner title ');
    $owner->setAttribute('summary', ' Owner summary ');
    config()->set('filament-seo.fallbacks.default', [
        'title' => 'name',
        'description' => 'summary',
        'canonical' => fn (Article $record): string => 'https://example.test/articles/'.$record->getKey(),
    ]);
    config()->set('filament-seo.defaults.title', 'Site title');
    config()->set('filament-seo.defaults.description', 'Site summary');

    $metadata = seoMetadata([
        'meta_title' => '  Explicit title  ',
        'meta_description' => " \t ",
        'canonical_url' => ' https://canonical.test/article ',
    ]);

    $resolved = app(SeoMetadataResolver::class)->resolve($owner, $metadata);

    expect($resolved)->toBeInstanceOf(ResolvedSeoMetadata::class)
        ->and($resolved->title)->toBe('Explicit title')
        ->and($resolved->description)->toBe('Owner summary')
        ->and($resolved->canonicalUrl)->toBe('https://canonical.test/article')
        ->and($resolved->robotsIndex)->toBeTrue()
        ->and($resolved->robotsFollow)->toBeTrue()
        ->and($metadata->getAttribute('meta_title'))->toBe('  Explicit title  ')
        ->and($metadata->getAttribute('meta_description'))->toBe(" \t ");
});

it('uses site defaults only after owner fallbacks and treats missing attributes as absent', function () {
    $owner = articleWithName('   ');
    config()->set('filament-seo.fallbacks.default', ['title' => 'missing_title', 'description' => 'missing_description']);
    config()->set('filament-seo.defaults', ['title' => ' Site title ', 'description' => ' Site description ', 'image' => null]);

    $resolved = app(SeoMetadataResolver::class)->resolve($owner);

    expect($resolved->title)->toBe('Site title')
        ->and($resolved->description)->toBe('Site description')
        ->and($resolved->canonicalUrl)->toBeNull();
});

it('applies package default then exact model then per-call fallback precedence', function () {
    $owner = articleWithName('Model title');
    config()->set('filament-seo.fallbacks', [
        'default' => ['title' => 'name'],
        'models' => [Article::class => ['title' => fn () => 'Model callback']],
    ]);

    $resolver = app(SeoMetadataResolver::class);

    expect($resolver->resolve($owner)->title)->toBe('Model callback')
        ->and($resolver->resolve($owner, fallbacks: ['title' => fn (Article $record) => $record->name.' override'])->title)
        ->toBe('Model title override');
});

it('supports owner fallback callbacks and normalizes empty and whitespace values', function () {
    $owner = articleWithName('Callback title');
    $resolved = app(SeoMetadataResolver::class)->resolve($owner, fallbacks: [
        'title' => fn (Article $record): string => '  '.$record->name.'  ',
        'description' => fn (): string => " \n ",
    ]);

    expect($resolved->title)->toBe('Callback title')
        ->and($resolved->description)->toBeNull();
});

it('rejects unsupported fallback configuration values clearly', function () {
    expect(fn () => app(SeoMetadataResolver::class)->resolve(
        articleWithName(),
        fallbacks: ['title' => ['not', 'an attribute or callback']],
    ))->toThrow(InvalidArgumentException::class);
});

it('does not read relationships, write records, or mutate the owner during resolution', function () {
    $owner = articleWithName();
    config()->set('filament-seo.fallbacks.default.title', 'seoMetadata');
    $before = $owner->getAttributes();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $resolved = app(SeoMetadataResolver::class)->resolve($owner);

    expect(DB::getQueryLog())->toBe([])
        ->and($owner->getAttributes())->toBe($before)
        ->and($owner->relationLoaded('seoMetadata'))->toBeFalse()
        ->and($resolved->title)->toBeNull()
        ->and(SeoMetadata::query()->count())->toBe(0);
});

it('preserves every stored robots boolean combination and defaults missing metadata to true', function (bool $index, bool $follow) {
    $resolved = app(SeoMetadataResolver::class)->resolve(
        articleWithName(),
        seoMetadata(['robots_index' => $index, 'robots_follow' => $follow]),
    );

    expect($resolved->robotsIndex)->toBe($index)->and($resolved->robotsFollow)->toBe($follow);
})->with([
    'true true' => [true, true],
    'true false' => [true, false],
    'false true' => [false, true],
    'false false' => [false, false],
]);

it('falls back from Twitter to Open Graph to SEO text and resolves image channels independently', function () {
    config()->set('filament-seo.images.url_resolver', fn (string $path, ?string $disk, Article $owner, string $channel): string => "https://cdn.example.test/{$channel}/".ltrim($path, '/'));
    $owner = articleWithName();
    $metadata = seoMetadata([
        'meta_title' => 'SEO title',
        'meta_description' => 'SEO description',
        'seo_image' => 'seo.jpg',
        'seo_image_alt' => 'SEO alt',
        'og_title' => 'OG title',
        'og_image' => 'og.jpg',
        'twitter_description' => 'Twitter description',
        'twitter_image' => 'twitter.jpg',
    ]);

    $resolved = app(SeoMetadataResolver::class)->resolve($owner, $metadata);

    expect($resolved->ogTitle)->toBe('OG title')
        ->and($resolved->ogDescription)->toBe('SEO description')
        ->and($resolved->twitterTitle)->toBe('OG title')
        ->and($resolved->twitterDescription)->toBe('Twitter description')
        ->and($resolved->seoImageUrl)->toBe('https://cdn.example.test/seo/seo.jpg')
        ->and($resolved->ogImageUrl)->toBe('https://cdn.example.test/og/og.jpg')
        ->and($resolved->twitterImageUrl)->toBe('https://cdn.example.test/twitter/twitter.jpg')
        ->and($resolved->ogImageAlt)->toBe('SEO alt')
        ->and($resolved->twitterImageAlt)->toBe('SEO alt');
});

it('uses a safe fallback image when a higher-precedence image is unsafe', function () {
    config()->set('filament-seo.images.url_resolver', fn (string $path): string => $path === 'unsafe.jpg' ? 'javascript:alert(1)' : 'https://cdn.example.test/'.$path);
    $owner = articleWithName();
    config()->set('filament-seo.defaults.image', 'site.jpg');

    $resolved = app(SeoMetadataResolver::class)->resolve($owner, seoMetadata(['seo_image' => 'unsafe.jpg']));

    expect($resolved->seoImageUrl)->toBe('https://cdn.example.test/site.jpg');
});

it('resolves relative images only when the disk has an explicit public policy', function () {
    config()->set('filesystems.disks.seo_private', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/seo_private'),
        'url' => 'https://private.example.test/storage',
        'visibility' => 'private',
    ]);
    Storage::fake('seo_private', ['url' => 'https://private.example.test/storage', 'visibility' => 'private']);
    config()->set('filament-seo.images.disk', 'seo_private');

    expect(app(SeoMetadataResolver::class)->resolve(
        articleWithName(),
        seoMetadata(['seo_image' => 'images/private.jpg']),
    )->seoImageUrl)->toBeNull();

    config()->set('filesystems.disks.seo_public', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/seo_public'),
        'url' => 'https://cdn.example.test/storage',
        'visibility' => 'public',
    ]);
    Storage::fake('seo_public', ['url' => 'https://cdn.example.test/storage', 'visibility' => 'public']);
    config()->set('filament-seo.images.disk', 'seo_public');

    $owner = articleWithName();
    $metadata = seoMetadata(['seo_image' => 'images/one.jpg']);
    $resolver = app(SeoMetadataResolver::class);

    expect($resolver->resolve($owner, $metadata)->seoImageUrl)->toBeNull();

    config()->set('filament-seo.images.public_disks', ['seo_public']);

    Storage::disk('seo_public')->put('images/one.jpg', 'test image content');

    expect($resolver->resolve($owner, $metadata)->seoImageUrl)
        ->toBe('https://cdn.example.test/storage/images/one.jpg');
});

it('never converts rooted or traversal filesystem paths into public image URLs', function () {
    config()->set('filament-seo.images.disk', 'seo_public');
    config()->set('filament-seo.images.public_disks', ['seo_public']);
    config()->set('filesystems.disks.seo_public', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/seo_public_paths'),
        'url' => 'https://cdn.example.test/storage',
        'visibility' => 'public',
    ]);
    Storage::fake('seo_public', ['url' => 'https://cdn.example.test/storage', 'visibility' => 'public']);

    $resolver = app(SeoMetadataResolver::class);

    foreach (['/private/storage/image.jpg', '../private/image.jpg', 'images/../../private.jpg', 'C:\\private\\image.jpg'] as $path) {
        expect($resolver->resolve(articleWithName(), seoMetadata(['seo_image' => $path]))->seoImageUrl)->toBeNull();
    }
});

it('validates image URLs and optional HTTP policy', function () {
    $owner = articleWithName();
    $resolver = app(SeoMetadataResolver::class);

    foreach ([
        'javascript:alert(1)',
        'data:image/png;base64,abc',
        'file:///etc/passwd',
        '//cdn.example.test/image.jpg',
        'https://',
        'https://bad host/image.jpg',
    ] as $unsafe) {
        expect($resolver->resolve($owner, seoMetadata(['seo_image' => $unsafe]))->seoImageUrl)->toBeNull();
    }

    expect($resolver->resolve($owner, seoMetadata(['seo_image' => ' https://cdn.example.test/image.jpg ']))->seoImageUrl)
        ->toBe('https://cdn.example.test/image.jpg')
        ->and($resolver->resolve($owner, seoMetadata(['seo_image' => 'http://cdn.example.test/image.jpg']))->seoImageUrl)
        ->toBeNull();

    config()->set('filament-seo.images.allow_http', true);

    expect($resolver->resolve($owner, seoMetadata(['seo_image' => 'http://cdn.example.test/image.jpg']))->seoImageUrl)
        ->toBe('http://cdn.example.test/image.jpg');
});

it('rejects malformed or unsafe host resolver output', function (string $output) {
    config()->set('filament-seo.images.url_resolver', fn () => $output);

    $resolved = app(SeoMetadataResolver::class)->resolve(
        articleWithName(),
        seoMetadata(['seo_image' => 'stored/path.jpg']),
    );

    expect($resolved->seoImageUrl)->toBeNull();
})->with([
    'protocol relative' => '//cdn.example.test/image.jpg',
    'dangerous scheme' => 'javascript:alert(1)',
    'malformed host' => 'https://bad host/image.jpg',
    'private path' => '/private/storage/image.jpg',
]);

it('resolves canonical URLs only from valid absolute HTTPS values', function () {
    $resolved = app(SeoMetadataResolver::class)->resolve(
        articleWithName(),
        seoMetadata(['canonical_url' => '//example.test/page']),
        ['canonical' => fn () => 'https://fallback.example.test/page'],
    );

    expect($resolved->canonicalUrl)->toBe('https://fallback.example.test/page');
});

it('evaluates completeness using effective values and keeps unloaded distinct from missing', function () {
    $analyzer = app(SeoCompletenessAnalyzer::class);
    $resolver = app(SeoMetadataResolver::class);
    $owner = articleWithName();
    $emptyResolution = $resolver->resolve($owner);
    $missing = $analyzer->analyze(null, $emptyResolution);

    expect($missing->status)->toBe(SeoCompleteness::STATUS_NO_STORED_METADATA)
        ->and($missing->issues)->toBe([
            ['id' => 'missing_meta_title', 'field' => 'meta_title'],
            ['id' => 'missing_meta_description', 'field' => 'meta_description'],
        ])
        ->and($missing->hasStoredMetadata)->toBeFalse();

    $unloaded = $analyzer->analyze(null, $emptyResolution, relationshipLoaded: false);

    expect($unloaded->status)->toBe(SeoCompleteness::STATUS_NOT_LOADED)
        ->and($unloaded->issues)->toBe([]);
});

it('counts configured fallback values toward completeness and reports missing stored state separately', function () {
    $owner = articleWithName();
    $resolved = app(SeoMetadataResolver::class)->resolve(
        $owner,
        fallbacks: ['title' => fn () => 'Fallback title', 'description' => fn () => 'Fallback description'],
    );
    $result = app(SeoCompletenessAnalyzer::class)->analyze(null, $resolved);

    expect($result->status)->toBe(SeoCompleteness::STATUS_COMPLETE)
        ->and($result->hasStoredMetadata)->toBeFalse()
        ->and($result->issues)->toBe([]);
});

it('evaluates metadata rows with missing required fields and ignores intentional robot directives', function () {
    $owner = articleWithName();
    $metadata = seoMetadata(['robots_index' => false, 'robots_follow' => false]);
    $resolved = app(SeoMetadataResolver::class)->resolve($owner, $metadata);
    $result = app(SeoCompletenessAnalyzer::class)->analyze($metadata, $resolved);

    expect($result->status)->toBe(SeoCompleteness::STATUS_NEEDS_ATTENTION)
        ->and($result->issues)->toHaveCount(2)
        ->and($resolved->robotsIndex)->toBeFalse()
        ->and($resolved->robotsFollow)->toBeFalse();
});

it('supports explicit completeness requirements and rejects unsupported fields', function () {
    $metadata = seoMetadata(['meta_title' => 'A title']);
    $resolved = app(SeoMetadataResolver::class)->resolve(articleWithName(), $metadata);

    $result = app(SeoCompletenessAnalyzer::class)->analyze(
        $metadata,
        $resolved,
        requiredFields: ['meta_title'],
    );

    expect($result->status)->toBe(SeoCompleteness::STATUS_COMPLETE)
        ->and(fn () => app(SeoCompletenessAnalyzer::class)->analyze($metadata, $resolved, requiredFields: ['robots_index']))
        ->toThrow(InvalidArgumentException::class);
});

it('exposes effective values through an immutable DTO', function () {
    $resolved = app(SeoMetadataResolver::class)->resolve(articleWithName(), seoMetadata(['meta_title' => 'Immutable']));

    expect($resolved->title)->toBe('Immutable')
        ->and(fn () => $resolved->title = 'Changed')
        ->toThrow(Error::class);
});
