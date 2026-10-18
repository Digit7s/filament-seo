<?php

namespace Digit7s\FilamentSeo\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesSeoSchema
{
    protected function createSeoSchema(): void
    {
        Schema::dropIfExists('seo_metadata');
        Schema::dropIfExists('news_categories');
        Schema::dropIfExists('articles');

        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_14_000000_create_seo_metadata_table.php';
        $migration->up();

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
    }
}
