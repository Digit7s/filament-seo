<?php

namespace Digit7s\FilamentSeo\Tests;

use Digit7s\FilamentSeo\FilamentSeoServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getApplicationBasePath(): string
    {
        return __DIR__.'/Fixtures/app';
    }

    protected function getPackageProviders($app): array
    {
        return [FilamentSeoServiceProvider::class, LivewireServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('FILAMENT_SEO_TEST_DB') ?: 'sqlite';
        $connection = $driver === 'pgsql'
            ? [
                'driver' => 'pgsql',
                'host' => getenv('FILAMENT_SEO_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('FILAMENT_SEO_TEST_DB_PORT') ?: '55432',
                'database' => getenv('FILAMENT_SEO_TEST_DB_NAME') ?: 'postgres',
                'username' => getenv('FILAMENT_SEO_TEST_DB_USER') ?: 'postgres',
                'password' => getenv('FILAMENT_SEO_TEST_DB_PASSWORD') ?: '',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ]
            : [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection);
        $app['config']->set('view.compiled', sys_get_temp_dir());
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function tearDown(): void
    {
        if ((getenv('FILAMENT_SEO_TEST_DB') ?: 'sqlite') === 'pgsql' && $this->app) {
            foreach (['seo_metadata', 'news_categories', 'articles'] as $table) {
                Schema::dropIfExists($table);
            }
        }

        File::deleteDirectory($this->getApplicationBasePath().'/config');
        File::deleteDirectory($this->getApplicationBasePath().'/database');
        File::delete($this->getApplicationBasePath().'/bootstrap/cache/packages.php');
        File::delete($this->getApplicationBasePath().'/bootstrap/cache/services.php');

        parent::tearDown();
    }
}
