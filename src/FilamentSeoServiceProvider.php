<?php

namespace Digit7s\FilamentSeo;

use Digit7s\FilamentSeo\Sitemap\SitemapRouteRegistrar;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class FilamentSeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filament-seo.php', 'filament-seo');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filament-seo');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-seo');
        Blade::componentNamespace('Digit7s\\FilamentSeo\\View\\Components', 'filament-seo');

        if (config('filament-seo.sitemap.enabled', false)) {
            SitemapRouteRegistrar::register();
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/filament-seo.php' => config_path('filament-seo.php'),
        ], 'filament-seo-config');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/filament-seo'),
        ], 'filament-seo-translations');

        $migration = __DIR__.'/../database/migrations/2026_10_14_000000_create_seo_metadata_table.php';
        $pagesMigration = __DIR__.'/../database/migrations/2026_10_19_000000_create_seo_pages_table.php';
        $structuredDataMigration = __DIR__.'/../database/migrations/2026_10_19_100000_add_structured_data_to_seo_metadata_table.php';

        $this->publishesMigrations([
            $migration => database_path('migrations/2026_10_14_000000_create_seo_metadata_table.php'),
            $pagesMigration => database_path('migrations/2026_10_19_000000_create_seo_pages_table.php'),
            $structuredDataMigration => database_path('migrations/2026_10_19_100000_add_structured_data_to_seo_metadata_table.php'),
        ], 'filament-seo-migrations');
    }
}
