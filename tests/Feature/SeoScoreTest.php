<?php

declare(strict_types=1);

use Digit7s\FilamentSeo\Data\SeoScoreResult;
use Digit7s\FilamentSeo\Filament\Tables\SeoScoreColumn;
use Digit7s\FilamentSeo\Models\SeoMetadata;
use Digit7s\FilamentSeo\Support\SeoMetadataResolver;
use Digit7s\FilamentSeo\Support\SeoScoreCalculator;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\Article;
use Digit7s\FilamentSeo\Tests\Support\CreatesSeoSchema;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

function calculatedSeoScore(?Model $metadata): SeoScoreResult
{
    $owner = Article::query()->create(['name' => 'Score fixture']);
    $resolved = app(SeoMetadataResolver::class)->resolve($owner, $metadata);

    return app(SeoScoreCalculator::class)->calculate($metadata, $resolved);
}

it('returns a neutral unscored state when metadata is missing', function (): void {
    $result = calculatedSeoScore(null);

    expect($result->score)->toBeNull()
        ->and($result->status)->toBe(SeoScoreResult::STATUS_NOT_CONFIGURED)
        ->and($result->label())->toBe('Not configured')
        ->and($result->color())->toBe('gray')
        ->and($result->summary())->toBe('No SEO metadata is configured for this record.');
});

it('calculates a deterministic normalized score and skips unset optional rules', function (): void {
    $metadata = new SeoMetadata([
        'meta_title' => str_repeat('T', 55),
        'meta_description' => str_repeat('D', 155),
        'robots_index' => true,
        'robots_follow' => true,
    ]);

    $first = calculatedSeoScore($metadata);
    $second = calculatedSeoScore($metadata);

    expect($first->score)->toBe(100)
        ->and($first->status)->toBe(SeoScoreResult::STATUS_GOOD)
        ->and($first->findings)->toBe([])
        ->and($first->applicableRules)->toBe(['meta_title', 'meta_title_length', 'meta_description', 'meta_description_length'])
        ->and($first->skippedRules)->toBe(['canonical_url', 'social_image_url'])
        ->and($first->score)->toBe($second->score)
        ->and($first->label())->toBe('Good · 100/100');
});

it('scores configured resolver fallbacks as the effective title and description', function (): void {
    config()->set('filament-seo.defaults.title', str_repeat('T', 55));
    config()->set('filament-seo.defaults.description', str_repeat('D', 155));
    $metadata = new SeoMetadata([
        'meta_title' => null,
        'meta_description' => null,
        'robots_index' => true,
        'robots_follow' => true,
    ]);

    $result = calculatedSeoScore($metadata);

    expect($result->score)->toBe(100)
        ->and($result->status)->toBe(SeoScoreResult::STATUS_GOOD)
        ->and($result->findings)->toBeEmpty();
});

it('applies inclusive title and description recommendation boundaries', function (string $title, string $description, bool $titleLengthPasses, bool $descriptionLengthPasses): void {
    $metadata = new SeoMetadata([
        'meta_title' => $title,
        'meta_description' => $description,
        'robots_index' => true,
        'robots_follow' => true,
    ]);

    $result = calculatedSeoScore($metadata);

    expect(in_array('meta_title_length', $result->findings, true))->toBe(! $titleLengthPasses)
        ->and(in_array('meta_description_length', $result->findings, true))->toBe(! $descriptionLengthPasses);
})->with([
    'title minimum and description minimum' => [str_repeat('T', 50), str_repeat('D', 150), true, true],
    'title maximum and description maximum' => [str_repeat('T', 60), str_repeat('D', 160), true, true],
    'title too short' => [str_repeat('T', 49), str_repeat('D', 155), false, true],
    'title too long' => [str_repeat('T', 61), str_repeat('D', 155), false, true],
    'description too short' => [str_repeat('T', 55), str_repeat('D', 149), true, false],
    'description too long' => [str_repeat('T', 55), str_repeat('D', 161), true, false],
]);

it('reports missing core values and skips their length rules', function (): void {
    $result = calculatedSeoScore(new SeoMetadata([
        'meta_title' => null,
        'meta_description' => null,
        'robots_index' => true,
        'robots_follow' => true,
    ]));

    expect($result->score)->toBe(0)
        ->and($result->status)->toBe(SeoScoreResult::STATUS_NEEDS_IMPROVEMENT)
        ->and($result->findings)->toBe(['meta_title', 'meta_description'])
        ->and($result->skippedRules)->toBe(['meta_title_length', 'meta_description_length', 'canonical_url', 'social_image_url']);
});

it('scores only configured canonical and social image fields without network checks', function (): void {
    $owner = Article::query()->create(['name' => 'Canonical and image']);
    config()->set('filament-seo.images.url_resolver', static fn (string $path): string => 'https://cdn.example.test/'.$path);
    $metadata = new SeoMetadata([
        'meta_title' => str_repeat('T', 55),
        'meta_description' => str_repeat('D', 155),
        'canonical_url' => 'javascript:alert(1)',
        'og_image' => 'social.png',
        'robots_index' => true,
        'robots_follow' => true,
    ]);
    $resolved = app(SeoMetadataResolver::class)->resolve($owner, $metadata);
    $result = app(SeoScoreCalculator::class)->calculate($metadata, $resolved);

    expect($resolved->canonicalUrl)->toBeNull()
        ->and($resolved->ogImageUrl)->toBe('https://cdn.example.test/social.png')
        ->and($result->score)->toBe(90)
        ->and($result->findings)->toBe(['canonical_url'])
        ->and($result->applicableRules)->toHaveCount(6);

    $metadata->setAttribute('canonical_url', 'https://example.test/score');
    $validResult = app(SeoScoreCalculator::class)->calculate(
        $metadata,
        app(SeoMetadataResolver::class)->resolve($owner, $metadata),
    );

    expect($validResult->score)->toBe(100)
        ->and($validResult->findings)->toBeEmpty();
});

it('applies the documented 80 point status threshold inclusively', function (): void {
    $owner = Article::query()->create(['name' => 'Threshold score']);
    $metadata = new SeoMetadata([
        'meta_title' => str_repeat('T', 49),
        'meta_description' => str_repeat('D', 155),
        'canonical_url' => 'javascript:alert(1)',
        'og_image' => '/private/image.png',
        'robots_index' => true,
        'robots_follow' => true,
    ]);
    $result = app(SeoScoreCalculator::class)->calculate(
        $metadata,
        app(SeoMetadataResolver::class)->resolve($owner, $metadata),
    );

    expect($result->score)->toBe(70)
        ->and($result->status)->toBe(SeoScoreResult::STATUS_NEEDS_IMPROVEMENT);

    $metadata->setAttribute('meta_title', str_repeat('T', 55));
    $thresholdResult = app(SeoScoreCalculator::class)->calculate(
        $metadata,
        app(SeoMetadataResolver::class)->resolve($owner, $metadata),
    );

    expect($thresholdResult->score)->toBe(80)
        ->and($thresholdResult->status)->toBe(SeoScoreResult::STATUS_GOOD);
});

it('does not penalize intentional noindex and exposes it as a distinct state', function (): void {
    $indexable = new SeoMetadata([
        'meta_title' => str_repeat('T', 55),
        'meta_description' => str_repeat('D', 155),
        'robots_index' => true,
        'robots_follow' => true,
    ]);
    $noindex = new SeoMetadata([
        'meta_title' => str_repeat('T', 55),
        'meta_description' => str_repeat('D', 155),
        'robots_index' => false,
        'robots_follow' => false,
    ]);

    $indexedResult = calculatedSeoScore($indexable);
    $noindexResult = calculatedSeoScore($noindex);

    expect($noindexResult->score)->toBe($indexedResult->score)
        ->and($noindexResult->status)->toBe(SeoScoreResult::STATUS_EXCLUDED)
        ->and($noindexResult->label())->toBe('Intentionally noindex · 100/100')
        ->and($noindexResult->color())->toBe('gray')
        ->and($noindexResult->summary())->toContain('Intentionally noindex');
});

it('uses the eager-loaded relationship without per-record queries and memoizes column callbacks', function (): void {
    $resolverCalls = 0;
    config()->set('filament-seo.images.url_resolver', static function (string $path) use (&$resolverCalls): string {
        $resolverCalls++;

        return 'https://cdn.example.test/'.$path;
    });

    $owner = Article::query()->create(['name' => 'Eager score']);
    $owner->seoMetadata()->create([
        'meta_title' => str_repeat('T', 55),
        'meta_description' => str_repeat('D', 155),
        'seo_image' => 'social.png',
        'robots_index' => true,
        'robots_follow' => true,
    ]);
    $recordIds = [$owner->getKey()];

    for ($index = 0; $index < 9; $index++) {
        $recordIds[] = Article::query()->create(['name' => 'Score page '.$index])->getKey();
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $records = Article::query()->with('seoMetadata')->whereKey($recordIds)->get();
    $fetchQueryCount = count(DB::getQueryLog());
    $record = $records->firstWhere('id', $owner->getKey());
    $column = SeoScoreColumn::make();
    $state = (new ReflectionProperty($column, 'getStateUsing'))->getValue($column);
    $color = (new ReflectionProperty($column, 'color'))->getValue($column);
    $icon = (new ReflectionProperty($column, 'icon'))->getValue($column);
    $tooltip = (new ReflectionProperty($column, 'tooltip'))->getValue($column);

    $label = $state($record);
    $badgeColor = $color($record);
    $badgeIcon = $icon($record);
    $summary = $tooltip($record);

    foreach ($records->where('id', '!=', $owner->getKey()) as $otherRecord) {
        $state($otherRecord);
        $color($otherRecord);
        $icon($otherRecord);
        $tooltip($otherRecord);
    }

    expect(count(DB::getQueryLog()))->toBe($fetchQueryCount)
        ->and($fetchQueryCount)->toBe(2)
        ->and($resolverCalls)->toBe(1)
        ->and($label)->toBe('Good · 100/100')
        ->and($badgeColor)->toBe('success')
        ->and($badgeIcon)->toBe('heroicon-o-check-circle')
        ->and($summary)->toContain('100/100');

    DB::disableQueryLog();
});

it('returns a neutral not-loaded state without querying the owner relationship', function (): void {
    $owner = Article::query()->create(['name' => 'Unloaded score']);
    $column = SeoScoreColumn::make();
    $state = (new ReflectionProperty($column, 'getStateUsing'))->getValue($column);
    $color = (new ReflectionProperty($column, 'color'))->getValue($column);
    $tooltip = (new ReflectionProperty($column, 'tooltip'))->getValue($column);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $label = $state($owner);

    expect($label)->toBe('Not loaded')
        ->and($color($owner))->toBe('gray')
        ->and($tooltip($owner))->toContain('Eager-load')
        ->and(DB::getQueryLog())->toBeEmpty()
        ->and($column)->toBeInstanceOf(TextColumn::class)
        ->and($column->isToggleable())->toBeTrue();

    DB::disableQueryLog();
});

it('distinguishes an eagerly loaded owner without metadata from an unloaded relationship', function (): void {
    $owner = Article::query()->create(['name' => 'Loaded empty score']);
    $owner->setRelation('seoMetadata', null);
    $column = SeoScoreColumn::make();
    $state = (new ReflectionProperty($column, 'getStateUsing'))->getValue($column);

    expect($state($owner))->toBe('Not configured');
});

it('supports custom relationship names and native TextColumn customization', function (): void {
    $owner = Article::query()->create(['name' => 'Custom relation score']);
    $owner->setRelation('seoMeta', new SeoMetadata([
        'meta_title' => str_repeat('T', 55),
        'meta_description' => str_repeat('D', 155),
        'robots_index' => true,
        'robots_follow' => true,
    ]));
    $column = SeoScoreColumn::make('custom_score', relationship: 'seoMeta')->label('SEO');
    $state = (new ReflectionProperty($column, 'getStateUsing'))->getValue($column);

    expect($column->getName())->toBe('custom_score')
        ->and($column->getLabel())->toBe('SEO')
        ->and($column->isToggleable())->toBeTrue()
        ->and($state($owner))->toBe('Good · 100/100');
});
