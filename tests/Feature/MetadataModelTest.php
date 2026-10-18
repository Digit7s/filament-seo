<?php

use Digit7s\FilamentSeo\Models\SeoMetadata;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\Article;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\NewsCategory;
use Digit7s\FilamentSeo\Tests\Support\CreatesSeoSchema;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

uses(CreatesSeoSchema::class);

beforeEach(function () {
    $this->createSeoSchema();
});

it('matches the Innwa metadata model contract and fields', function () {
    $metadata = new SeoMetadata;
    $fields = [
        'meta_title', 'meta_description', 'keywords', 'canonical_url',
        'seo_image', 'seo_image_alt', 'og_title', 'og_description',
        'og_image', 'og_image_alt', 'twitter_title', 'twitter_description',
        'twitter_image', 'twitter_image_alt', 'robots_index', 'robots_follow',
    ];

    expect($metadata->getTable())->toBe('seo_metadata')
        ->and($metadata->getKeyName())->toBe('id')
        ->and($metadata->getKeyType())->toBe('int')
        ->and($metadata->getIncrementing())->toBeTrue()
        ->and($metadata->usesTimestamps())->toBeTrue()
        ->and($metadata->getFillable())->toBe($fields)
        ->and($metadata->getCasts())->toMatchArray([
            'robots_index' => 'boolean',
            'robots_follow' => 'boolean',
        ])
        ->and($metadata->seoable())->toBeInstanceOf(MorphTo::class);
});

it('supports unrelated morph owners without changing their stored morph identities', function () {
    $article = Article::create(['name' => 'A']);
    $category = NewsCategory::create(['name' => 'B']);

    $articleMetadata = $article->seoMetadata()->create(['meta_title' => 'Article title']);
    $categoryMetadata = $category->seoMetadata()->create(['meta_title' => 'Category title']);

    expect($article->seoMetadata()->first()->is($articleMetadata))->toBeTrue()
        ->and($category->seoMetadata()->first()->is($categoryMetadata))->toBeTrue()
        ->and($article->getKey())->toBe($category->getKey())
        ->and($articleMetadata->seoable_type)->toBe(Article::class)
        ->and($categoryMetadata->seoable_type)->toBe(NewsCategory::class)
        ->and(Relation::morphMap())->toBe([]);
});

it('reads a missing relation without creating a metadata row', function () {
    $article = Article::create(['name' => 'A']);

    expect($article->seoMetadata()->first())->toBeNull()
        ->and(DB::table('seo_metadata')->count())->toBe(0);
});

it('uses database true defaults for robot fields when values are omitted', function () {
    $article = Article::create(['name' => 'A']);
    $metadata = $article->seoMetadata()->create(['meta_title' => 'A title']);

    expect($metadata->fresh()->robots_index)->toBeTrue()
        ->and($metadata->fresh()->robots_follow)->toBeTrue();
});

it('updates existing related metadata and preserves explicit false robot values', function () {
    $article = Article::create(['name' => 'A']);
    $metadata = $article->seoMetadata()->create([
        'meta_title' => 'Old title',
        'robots_index' => false,
        'robots_follow' => false,
    ]);

    $article->seoMetadata()->updateOrCreate([], ['meta_title' => 'New title']);
    $metadata->refresh();

    expect($metadata->meta_title)->toBe('New title')
        ->and($metadata->robots_index)->toBeFalse()
        ->and($metadata->robots_follow)->toBeFalse()
        ->and((bool) DB::table('seo_metadata')->where('id', $metadata->getKey())->value('robots_index'))->toBeFalse()
        ->and((bool) DB::table('seo_metadata')->where('id', $metadata->getKey())->value('robots_follow'))->toBeFalse();
});

it('enforces one metadata row per polymorphic owner pair', function () {
    $article = Article::create(['name' => 'A']);
    $article->seoMetadata()->create(['meta_title' => 'First']);

    expect(fn () => $article->seoMetadata()->create(['meta_title' => 'Duplicate']))
        ->toThrow(QueryException::class);
});

it('publishes the expected schema including morph and unique indexes', function () {
    $columns = collect(Schema::getColumns('seo_metadata'))->keyBy('name');
    $indexes = collect(Schema::getIndexes('seo_metadata'))->keyBy('name');
    $migrationSource = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_10_14_000000_create_seo_metadata_table.php');
    $defaultIsTrue = fn (mixed $value): bool => in_array(strtolower(trim((string) $value, "'\"() ")), ['1', 'true'], true);

    expect($columns->keys()->all())->toBe([
        'id', 'seoable_type', 'seoable_id', 'meta_title', 'meta_description',
        'keywords', 'canonical_url', 'seo_image', 'seo_image_alt', 'og_title',
        'og_description', 'og_image', 'og_image_alt', 'twitter_title',
        'twitter_description', 'twitter_image', 'twitter_image_alt',
        'robots_index', 'robots_follow', 'created_at', 'updated_at',
    ])
        ->and($columns['meta_title']['nullable'])->toBeTrue()
        ->and($columns['meta_description']['nullable'])->toBeTrue()
        ->and(in_array($columns['meta_title']['type'], ['varchar', 'character varying(255)'], true))->toBeTrue()
        ->and($columns['meta_description']['type'])->toBe('text')
        ->and($columns['keywords']['type'])->toBe('text')
        ->and($columns['robots_index']['nullable'])->toBeFalse()
        ->and($columns['robots_follow']['nullable'])->toBeFalse()
        ->and($defaultIsTrue($columns['robots_index']['default']))->toBeTrue()
        ->and($defaultIsTrue($columns['robots_follow']['default']))->toBeTrue()
        ->and($indexes->values()->contains(fn (array $index): bool => $index['columns'] === ['seoable_type', 'seoable_id'] && $index['unique']))->toBeTrue()
        ->and($indexes->values()->contains(fn (array $index): bool => $index['columns'] === ['seoable_type', 'seoable_id'] && ! $index['unique']))->toBeTrue()
        ->and($migrationSource)->toContain("\$table->string('meta_title')->nullable();")
        ->and($migrationSource)->toContain("\$table->string('canonical_url')->nullable();")
        ->and($migrationSource)->toContain("\$table->string('twitter_image_alt')->nullable();");
});
