<?php

use Digit7s\FilamentSeo\Models\SeoMetadata;
use Digit7s\FilamentSeo\Models\SeoPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('upgrades and rolls back the v0.1 metadata schema without losing existing records', function (): void {
    Schema::dropIfExists('seo_pages');
    Schema::dropIfExists('seo_metadata');

    $baseMigration = require dirname(__DIR__, 2).'/database/migrations/2026_10_14_000000_create_seo_metadata_table.php';
    $pagesMigration = require dirname(__DIR__, 2).'/database/migrations/2026_10_19_000000_create_seo_pages_table.php';
    $structuredDataMigration = require dirname(__DIR__, 2).'/database/migrations/2026_10_19_100000_add_structured_data_to_seo_metadata_table.php';

    $baseMigration->up();
    DB::table('seo_metadata')->insert([
        'seoable_type' => 'App\\Models\\Article',
        'seoable_id' => 7,
        'meta_title' => 'Existing title',
        'meta_description' => 'Existing description',
        'robots_index' => true,
        'robots_follow' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pagesMigration->up();
    $structuredDataMigration->up();
    $page = SeoPage::query()->create([
        'name' => 'Upgrade page',
        'page_key' => 'upgrade-page',
        'path' => '/upgrade-page',
    ]);
    $page->seoMetadata()->create([
        'structured_data_enabled' => true,
        'structured_data_type' => 'AboutPage',
        'structured_data' => ['name' => 'Upgrade page'],
    ]);

    expect(DB::table('seo_metadata')->where('id', 1)->value('meta_title'))->toBe('Existing title')
        ->and(DB::table('seo_metadata')->where('id', 1)->value('structured_data_enabled'))->toBe(0)
        ->and(SeoMetadata::query()->where('seoable_type', SeoPage::class)->count())->toBe(1)
        ->and(Schema::hasTable('seo_pages'))->toBeTrue();

    $structuredDataMigration->down();
    $pagesMigration->down();

    expect(DB::table('seo_metadata')->where('id', 1)->value('meta_description'))->toBe('Existing description')
        ->and(Schema::hasTable('seo_metadata'))->toBeTrue()
        ->and(Schema::hasTable('seo_pages'))->toBeFalse()
        ->and(Schema::hasColumn('seo_metadata', 'structured_data_enabled'))->toBeFalse();
});
