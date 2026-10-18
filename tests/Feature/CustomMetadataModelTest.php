<?php

use Digit7s\FilamentSeo\Models\SeoMetadata;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\ApplicationDefinedOwner;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\Article;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\GlobalSeoMetadata;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\InvalidTableSeoMetadata;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\OwnerSeoMetadata;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\OwnerWithCustomMetadata;
use Digit7s\FilamentSeo\Tests\Support\CreatesSeoSchema;
use Illuminate\Database\Eloquent\Relations\MorphOne;

uses(CreatesSeoSchema::class);

beforeEach(function () {
    $this->createSeoSchema();
});

it('uses the package model by default', function () {
    $article = new Article;

    expect($article->seoMetadata())->toBeInstanceOf(MorphOne::class)
        ->and($article->seoMetadata()->getRelated())->toBeInstanceOf(SeoMetadata::class);
});

it('uses the globally configured compatible custom model', function () {
    config(['filament-seo.model' => GlobalSeoMetadata::class]);

    expect((new Article)->seoMetadata()->getRelated())->toBeInstanceOf(GlobalSeoMetadata::class);
});

it('gives an owner model override precedence over global model configuration', function () {
    config(['filament-seo.model' => GlobalSeoMetadata::class]);

    expect((new OwnerWithCustomMetadata)->seoMetadata()->getRelated())->toBeInstanceOf(OwnerSeoMetadata::class);
});

it('rejects configured classes that are not Eloquent models', function () {
    config(['filament-seo.model' => stdClass::class]);

    expect(fn () => (new Article)->seoMetadata())
        ->toThrow(InvalidArgumentException::class, 'must exist and extend');
});

it('rejects Eloquent models that violate fixed table compatibility', function () {
    config(['filament-seo.model' => InvalidTableSeoMetadata::class]);

    expect(fn () => (new Article)->seoMetadata())
        ->toThrow(InvalidArgumentException::class, 'table name seo_metadata');
});

it('allows an application to retain its own compatible relation without the package trait', function () {
    $owner = new ApplicationDefinedOwner;

    expect($owner->seoMetadata())->toBeInstanceOf(MorphOne::class)
        ->and($owner->seoMetadata()->getRelated())->toBeInstanceOf(OwnerSeoMetadata::class);
});
