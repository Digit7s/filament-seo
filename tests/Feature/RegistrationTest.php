<?php

use Digit7s\FilamentSeo\FilamentSeoServiceProvider;
use Digit7s\FilamentSeo\Models\SeoMetadata;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\Article;
use Digit7s\FilamentSeo\Tests\Support\CreatesSeoSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(CreatesSeoSchema::class);

it('declares the Laravel provider for Composer package discovery', function () {
    $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['extra']['laravel']['providers'])
        ->toContain(FilamentSeoServiceProvider::class);
});

it('merges package defaults without running or loading its migration', function () {
    expect(config('filament-seo.model'))->toBe(SeoMetadata::class)
        ->and(config('filament-seo.relationship'))->toBe('seoMetadata')
        ->and(config('filament-seo.images.visibility'))->toBe('private')
        ->and(Schema::hasTable('seo_metadata'))->toBeFalse()
        ->and(Schema::hasTable('seo_pages'))->toBeFalse();
});

it('publishes the package config and migration only when explicitly requested', function () {
    expect(Schema::hasTable('seo_metadata'))->toBeFalse();

    $this->artisan('vendor:publish', ['--tag' => 'filament-seo-config', '--force' => true])
        ->assertExitCode(0);

    $configPath = $this->app->configPath('filament-seo.php');
    expect(is_file($configPath))->toBeTrue();

    $this->artisan('vendor:publish', ['--tag' => 'filament-seo-migrations', '--force' => true])
        ->assertExitCode(0);

    $publishedMigrations = glob($this->app->databasePath('migrations/*_create_*_table.php')) ?: [];
    $publishedPagesMigration = glob($this->app->databasePath('migrations/*_create_seo_pages_table.php')) ?: [];

    expect($publishedMigrations)->toHaveCount(2)
        ->and($publishedPagesMigration)->toHaveCount(1)
        ->and(Schema::hasTable('seo_metadata'))->toBeFalse()
        ->and(Schema::hasTable('seo_pages'))->toBeFalse();
});

it('leaves an existing compatible table and stored records unchanged when the provider boots', function () {
    $this->createSeoSchema();

    $article = Article::create(['name' => 'Existing article']);
    $metadata = $article->seoMetadata()->create([
        'meta_title' => 'Existing SEO title',
        'robots_index' => false,
    ]);

    $provider = new FilamentSeoServiceProvider($this->app);
    $provider->register();
    $provider->boot();

    expect(Schema::hasTable('seo_metadata'))->toBeTrue()
        ->and($metadata->fresh()->meta_title)->toBe('Existing SEO title')
        ->and($metadata->fresh()->robots_index)->toBeFalse()
        ->and(DB::table('seo_metadata')->count())->toBe(1);
});
