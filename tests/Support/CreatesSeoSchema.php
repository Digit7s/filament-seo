<?php

namespace Digit7s\FilamentSeo\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesSeoSchema
{
    protected function createSeoSchema(): void
    {
        Schema::dropIfExists('seo_metadata');
        Schema::dropIfExists('seo_pages');
        Schema::dropIfExists('news_categories');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('sitemap_articles');

        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_14_000000_create_seo_metadata_table.php';
        $migration->up();

        $pagesMigration = require dirname(__DIR__, 2).'/database/migrations/2026_10_19_000000_create_seo_pages_table.php';
        $pagesMigration->up();

        $structuredDataMigration = require dirname(__DIR__, 2).'/database/migrations/2026_10_19_100000_add_structured_data_to_seo_metadata_table.php';
        $structuredDataMigration->up();

        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('news_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('sitemap_articles', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('slug')->unique();
            $table->string('status');
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
