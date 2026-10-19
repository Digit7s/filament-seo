<?php

declare(strict_types=1);

use Digit7s\FilamentSeo\Data\SeoScoreResult;
use Digit7s\FilamentSeo\Filament\Forms\SeoFields;
use Digit7s\FilamentSeo\Filament\Infolists\SeoMetadataInfolistSection;
use Digit7s\FilamentSeo\Filament\Tables\SeoScoreColumn;
use Digit7s\FilamentSeo\Filament\Tables\SeoStatusColumn;
use Digit7s\FilamentSeo\FilamentSeoServiceProvider;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\Article;
use Digit7s\FilamentSeo\Tests\Support\CreatesSeoSchema;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

uses(CreatesSeoSchema::class);

beforeEach(function (): void {
    $this->createSeoSchema();
});

it('registers all supported package translation catalogs with matching keys', function (): void {
    $english = require __DIR__.'/../../resources/lang/en/filament-seo.php';

    foreach (['my', 'th'] as $locale) {
        $translations = require __DIR__."/../../resources/lang/{$locale}/filament-seo.php";

        expect(array_keys($translations))->toBe(array_keys($english))
            ->and(translationKeyShape($translations))->toBe(translationKeyShape($english));
    }

    foreach (['en', 'my', 'th'] as $locale) {
        expect(Lang::has('filament-seo::filament-seo.groups.basic', $locale))->toBeTrue()
            ->and(Lang::has('filament-seo::filament-seo.groups.structured_data', $locale))->toBeTrue()
            ->and(Lang::has('filament-seo::filament-seo.messages.seo_score.no_findings', $locale))->toBeTrue();
    }
});

it('translates component labels and score findings in each supported locale', function (): void {
    $section = SeoFields::make(tabbed: true, fields: ['meta_title']);
    $basicTab = $section->getDefaultChildComponents()[0]->getDefaultChildComponents()[0];
    $titleField = $basicTab->getDefaultChildComponents()[0];
    $infolist = SeoMetadataInfolistSection::make(groups: ['basic'], showResolvedValues: false, showCompleteness: false);
    $titleEntry = $infolist->getDefaultChildComponents()[0];
    $owner = Article::query()->create(['name' => 'Localization fixture']);
    $owner->seoMetadata()->create(['meta_title' => 'Short title']);
    $owner->load('seoMetadata');
    $statusColumn = SeoStatusColumn::make();
    $statusState = (new ReflectionProperty($statusColumn, 'getStateUsing'))->getValue($statusColumn);

    foreach ([
        'en' => ['Basic SEO', 'Meta title', 'Meta title is missing.', 'Needs attention'],
        'my' => ['အခြေခံ SEO', 'Meta ခေါင်းစဉ်', 'Meta ခေါင်းစဉ် မရှိပါ။', 'စစ်ဆေးရန် လိုအပ်သည်'],
        'th' => ['SEO พื้นฐาน', 'ชื่อเมตา', 'ไม่มีชื่อเมตา', 'ต้องตรวจสอบ'],
    ] as $locale => [$group, $field, $finding, $status]) {
        App::setLocale($locale);

        expect($basicTab->getLabel())->toBe($group)
            ->and($titleField->getLabel())->toBe($field)
            ->and($titleEntry->getLabel())->toBe($field)
            ->and((new SeoScoreResult(0, SeoScoreResult::STATUS_NEEDS_IMPROVEMENT, ['meta_title']))->summary())
            ->toContain($finding)
            ->and($statusState($owner))->toBe($status)
            ->and(SeoStatusColumn::make()->getLabel())
            ->toBe(__('filament-seo::filament-seo.groups.seo'))
            ->and(SeoScoreColumn::make()->getLabel())
            ->toBe(__('filament-seo::filament-seo.groups.seo_score'));
    }

    App::setLocale('en');
});

it('keeps consumer labels intact and resolves translation fallback at render time', function (): void {
    $field = SeoFields::make(fields: ['meta_title'], labels: ['meta_title' => 'Editorial headline'])
        ->getDefaultChildComponents()[0]->getDefaultChildComponents()[0];

    expect($field->getLabel())->toBe('Editorial headline');

    $translatedField = SeoFields::make(fields: ['meta_title'])
        ->getDefaultChildComponents()[0]->getDefaultChildComponents()[0];

    App::setLocale('my');
    expect($translatedField->getLabel())->toBe('Meta ခေါင်းစဉ်');

    App::setLocale('unsupported');
    expect(__('filament-seo::filament-seo.fields.meta_title'))->toBe('Meta title');

    $partialLocalePath = $this->app->langPath('vendor/filament-seo/my/filament-seo.php');
    File::ensureDirectoryExists(dirname($partialLocalePath));
    File::put($partialLocalePath, "<?php return ['groups' => ['seo' => 'SEO မီတာဒေတာ']];");
    app('translator')->setLoaded([]);

    try {
        App::setLocale('my');
        expect(__('filament-seo::filament-seo.fields.meta_title'))->toBe('Meta ခေါင်းစဉ်');
    } finally {
        File::deleteDirectory(dirname(dirname($partialLocalePath)));
        $vendorTranslationsPath = dirname(dirname(dirname($partialLocalePath)));

        if (File::isDirectory($vendorTranslationsPath) && File::isEmptyDirectory($vendorTranslationsPath)) {
            File::deleteDirectory($vendorTranslationsPath);
        }

        $langPath = dirname($vendorTranslationsPath);

        if (File::isDirectory($langPath) && File::isEmptyDirectory($langPath)) {
            File::deleteDirectory($langPath);
        }

        app('translator')->setLoaded([]);
    }

    App::setLocale('en');
});

it('allows consumers to override published package translations', function (): void {
    $path = $this->app->langPath('vendor/filament-seo/en/filament-seo.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, "<?php return ['groups' => ['seo' => 'Search settings']];");

    try {
        expect(__('filament-seo::filament-seo.groups.seo'))->toBe('Search settings');
    } finally {
        File::deleteDirectory(dirname(dirname($path)));
        $vendorTranslationsPath = dirname(dirname(dirname($path)));

        if (File::isDirectory($vendorTranslationsPath) && File::isEmptyDirectory($vendorTranslationsPath)) {
            File::deleteDirectory($vendorTranslationsPath);
        }

        $langPath = dirname($vendorTranslationsPath);

        if (File::isDirectory($langPath) && File::isEmptyDirectory($langPath)) {
            File::deleteDirectory($langPath);
        }

        App::setLocale('en');
    }

    $published = FilamentSeoServiceProvider::pathsToPublish(FilamentSeoServiceProvider::class, 'filament-seo-translations');
    expect(collect($published)->keys()->contains(fn (string $source): bool => str_ends_with($source, '/resources/lang')))
        ->toBeTrue();
});

/** @return list<string> */
function translationKeyShape(array $translations, string $prefix = ''): array
{
    $shape = [];

    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        $shape[] = $path;

        if (is_array($value)) {
            $shape = [...$shape, ...translationKeyShape($value, $path)];
        }
    }

    sort($shape);

    return $shape;
}
