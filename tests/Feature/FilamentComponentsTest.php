<?php

declare(strict_types=1);

use Digit7s\FilamentSeo\Filament\Forms\SeoFields;
use Digit7s\FilamentSeo\Filament\Infolists\SeoMetadataInfolistSection;
use Digit7s\FilamentSeo\Filament\Tables\SeoMissingFilter;
use Digit7s\FilamentSeo\Filament\Tables\SeoStatusColumn;
use Digit7s\FilamentSeo\Models\SeoMetadata;
use Digit7s\FilamentSeo\Tests\Fixtures\Components\SeoFormHarness;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\Article;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\NewsCategory;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\TenantScopedArticle;
use Digit7s\FilamentSeo\Tests\Support\CreatesSeoSchema;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(CreatesSeoSchema::class);

beforeEach(function (): void {
    $this->createSeoSchema();
});

it('returns native Filament form, table, filter, and infolist components', function (): void {
    $section = SeoFields::make(
        imageDirectories: ['seo' => fn (): string => 'articles/new/seo'],
        defaults: ['robots_index' => true],
        recommendations: ['meta_title' => 'Suggested editorial length: 50–60 characters.'],
    );

    expect($section)->toBeInstanceOf(Section::class)
        ->and($section->getRelationshipName())->toBe('seoMetadata')
        ->and(SeoStatusColumn::make())->toBeInstanceOf(TextColumn::class)
        ->and(SeoMissingFilter::make())->toBeInstanceOf(Filter::class)
        ->and(SeoMetadataInfolistSection::make())->toBeInstanceOf(Section::class);

    $basicGroup = $section->getDefaultChildComponents()[0];
    $upload = $basicGroup->getDefaultChildComponents()[4];
    expect($upload)->toBeInstanceOf(FileUpload::class)
        ->and($upload->getVisibility())->toBe('private')
        ->and($upload->getMaxSize())->toBe(10240)
        ->and($upload->getDirectory())->toBe('articles/new/seo');
});

it('offers native tabbed groups without changing the existing section API', function (): void {
    $section = SeoFields::make(tabbed: true, fields: ['meta_title', 'og_title', 'robots_index']);
    $tabs = $section->getDefaultChildComponents()[0];
    $items = $tabs->getDefaultChildComponents();

    expect($section)->toBeInstanceOf(Section::class)
        ->and($section->getRelationshipName())->toBe('seoMetadata')
        ->and($tabs)->toBeInstanceOf(Tabs::class)
        ->and($tabs->getActiveTab())->toBe(1)
        ->and($tabs->isContained())->toBeFalse()
        ->and($items)->toHaveCount(3)
        ->and(array_map(fn (Tab $tab): ?string => $tab->getLabel(), $items))
        ->toBe(['Basic SEO', 'Social metadata', 'Robots directives'])
        ->and(array_map(fn (Tab $tab): array => array_map(
            fn ($field): ?string => $field->getName(),
            $tab->getDefaultChildComponents(),
        ), $items))->toBe([['meta_title'], ['og_title'], ['robots_index']]);
});

it('preserves unsaved values across tab groups and exposes inactive tab validation errors', function (): void {
    $owner = Article::query()->create(['name' => 'Tabbed article']);

    $form = Livewire::test(SeoFormHarness::class, ['recordId' => $owner->getKey()])
        ->set('tabbed', true)
        ->set('data.seoMetadata.meta_title', 'Unsaved basic title')
        ->set('data.seoMetadata.og_title', 'Unsaved social title');

    expect($form->get('data.seoMetadata'))->toMatchArray([
        'meta_title' => 'Unsaved basic title',
        'og_title' => 'Unsaved social title',
    ]);

    $form->call('save')->assertHasNoErrors();

    expect($owner->seoMetadata()->firstOrFail()->only(['meta_title', 'og_title']))
        ->toBe(['meta_title' => 'Unsaved basic title', 'og_title' => 'Unsaved social title']);

    $invalidForm = Livewire::test(SeoFormHarness::class, ['recordId' => $owner->getKey()])
        ->set('tabbed', true)
        ->set('requireSocialTitle', true)
        ->set('data.seoMetadata.og_title', '')
        ->call('save')
        ->assertHasErrors(['data.seoMetadata.og_title' => 'required']);

    $instance = $invalidForm->instance();
    $schemas = (new ReflectionProperty($instance, 'cachedSchemas'))->getValue($instance);
    $seoSection = array_values($schemas)[0]->getComponents()[0];
    $tabs = $seoSection->getChildComponents()[0];
    $socialTab = $tabs->getChildComponents()[1];

    expect($socialTab->getBadge())->toBe('1');
});

it('rejects unknown groups and field identifiers', function (): void {
    expect(fn () => SeoFields::make(groups: ['advanced']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => SeoFields::make(fields: ['unexpected_column']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => SeoFields::make(maxLengths: ['seo_image' => 2048]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => SeoMissingFilter::make(requiredFields: ['unexpected_column']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => SeoMetadataInfolistSection::make(groups: ['advanced']))
        ->toThrow(InvalidArgumentException::class);
});

it('registers the overrideable English package translations', function (): void {
    expect(__('filament-seo::filament-seo.fields.meta_title'))->toBe('Meta title')
        ->and(__('filament-seo::filament-seo.statuses.not_loaded'))->toBe('Metadata not loaded');
});

it('filters missing, null, empty, whitespace, and custom stored fields with grouped constraints', function (): void {
    $without = Article::query()->create(['name' => 'without']);
    $null = Article::query()->create(['name' => 'null']);
    $null->seoMetadata()->create(['meta_title' => null, 'meta_description' => 'Description']);
    $empty = Article::query()->create(['name' => 'empty']);
    $empty->seoMetadata()->create(['meta_title' => '', 'meta_description' => 'Description']);
    $spaces = Article::query()->create(['name' => 'spaces']);
    $spaces->seoMetadata()->create(['meta_title' => " \t ", 'meta_description' => 'Description']);
    $missingDescription = Article::query()->create(['name' => 'missing-description']);
    $missingDescription->seoMetadata()->create(['meta_title' => 'Title', 'meta_description' => '   ']);
    $complete = Article::query()->create(['name' => 'complete']);
    $complete->seoMetadata()->create(['meta_title' => 'Title', 'meta_description' => 'Description']);

    $filter = SeoMissingFilter::make();
    $query = Article::query()->where('name', '!=', 'complete');
    $filtered = $filter->apply($query, ['isActive' => true])->pluck('name')->all();

    expect($filtered)->toEqualCanonicalizing(['without', 'null', 'empty', 'spaces', 'missing-description']);

    $custom = SeoMissingFilter::make(name: 'title_missing', requiredFields: ['meta_title']);
    expect($custom->apply(Article::query(), ['isActive' => true])->pluck('name')->all())
        ->toEqualCanonicalizing(['without', 'null', 'empty', 'spaces']);

    $noRequirements = SeoMissingFilter::make(name: 'row_missing', requiredFields: []);
    expect($noRequirements->apply(Article::query(), ['isActive' => true])->pluck('name')->all())
        ->toBe([$without->name]);
});

it('preserves surrounding query constraints and global scopes through relationship predicates', function (): void {
    $first = Article::query()->create(['name' => 'tenant-a-missing']);
    Article::query()->create(['name' => 'tenant-b-missing']);

    $query = Article::query()->where('name', 'like', 'tenant-a%');
    $results = SeoMissingFilter::make()->apply($query, ['isActive' => true])->get();

    expect($results->modelKeys())->toBe([$first->getKey()]);

    $scoped = TenantScopedArticle::query()->create(['name' => 'tenant-a-scoped']);
    DB::table('articles')->insert([
        'name' => 'tenant-b-scoped',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(SeoMissingFilter::make()->apply(TenantScopedArticle::query(), ['isActive' => true])->pluck('name')->all())
        ->toEqualCanonicalizing(['tenant-a-missing', $scoped->name]);
});

it('keeps status rendering query-free and distinguishes unloaded from absent metadata', function (): void {
    $owner = Article::query()->create(['name' => 'Article']);
    $column = SeoStatusColumn::make(issuesTooltip: true, showCompleteness: true);
    $stateCallback = (new ReflectionProperty($column, 'getStateUsing'))->getValue($column);
    $tooltipCallback = (new ReflectionProperty($column, 'tooltip'))->getValue($column);

    DB::enableQueryLog();
    $unloadedState = $stateCallback($owner);
    $unloadedTooltip = $tooltipCallback($owner);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($unloadedState)->toContain('Metadata not loaded')
        ->and($unloadedTooltip)->toBe('Metadata not loaded')
        ->and($queries)->toBeEmpty();

    $owner->setRelation('seoMetadata', null);
    expect($stateCallback($owner))->toContain('No stored metadata');

    $owner->seoMetadata()->create(['meta_title' => 'Title', 'meta_description' => 'Description']);
    $owner->unsetRelation('seoMetadata');
    $owner->load('seoMetadata');
    $tooltip = $tooltipCallback($owner);
    expect($stateCallback($owner))->toBe('Complete')
        ->and($tooltip)->toBeNull();
});

it('uses analyzer statuses and keeps eager-loaded status work bounded across row counts', function (): void {
    $complete = Article::query()->create(['name' => 'Complete']);
    $complete->seoMetadata()->create(['meta_title' => 'Title', 'meta_description' => 'Description']);
    $attention = Article::query()->create(['name' => 'Attention']);
    $attention->seoMetadata()->create(['meta_title' => 'Title']);
    Article::query()->create(['name' => 'Missing']);

    $column = SeoStatusColumn::make();
    $stateCallback = (new ReflectionProperty($column, 'getStateUsing'))->getValue($column);
    $issueColumn = SeoStatusColumn::make(issuesTooltip: true);
    $tooltipCallback = (new ReflectionProperty($issueColumn, 'tooltip'))->getValue($issueColumn);
    $colorCallback = (new ReflectionProperty($column, 'color'))->getValue($column);

    foreach ([1, 3] as $limit) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $records = Article::query()->with('seoMetadata')->limit($limit)->get();
        $fetchQueryCount = count(DB::getQueryLog());

        foreach ($records as $record) {
            $stateCallback($record);
        }

        expect(count(DB::getQueryLog()))->toBe($fetchQueryCount);
        expect($fetchQueryCount)->toBe(2);
        DB::disableQueryLog();
    }

    $complete->load('seoMetadata');
    $attention->load('seoMetadata');
    expect($stateCallback($complete))->toBe('Complete')
        ->and($stateCallback($attention))->toContain('Needs attention');

    expect($colorCallback($complete))->toBe('success')
        ->and($colorCallback($attention))->toBe('warning')
        ->and($tooltipCallback($attention))->toBe('Meta description is missing.');
});

it('resolves infolist state through the loaded relation and suppresses private image paths', function (): void {
    config()->set('filament-seo.fallbacks.default.title', 'name');
    config()->set('filament-seo.images.public_disks', []);

    $owner = Article::query()->create(['name' => 'Fallback owner']);
    $metadata = $owner->seoMetadata()->create([
        'meta_title' => null,
        'meta_description' => 'Stored description',
        'seo_image' => 'private/seo/image.jpg',
        'robots_index' => false,
        'robots_follow' => true,
    ]);
    $owner->setRelation('seoMetadata', $metadata);

    $section = SeoMetadataInfolistSection::make(groups: ['basic', 'robots'], imagePreview: true);
    $entries = collect($section->getDefaultChildComponents())->keyBy(fn ($entry) => $entry->getName());
    $state = fn (string $key) => (new ReflectionProperty($entries[$key], 'getConstantStateUsing'))->getValue($entries[$key]);

    expect($state('meta_description')($owner))->toBe('Stored description')
        ->and($state('robots_index')($owner))->toBe('Disabled')
        ->and($state('robots_follow')($owner))->toBe('Enabled')
        ->and($state('resolved_title')($owner))->toBe('Fallback owner')
        ->and($state('seo_image')($owner))->toBeNull()
        ->and($entries['seo_image'])->toBeInstanceOf(ImageEntry::class)
        ->and($entries['meta_title'])->toBeInstanceOf(TextEntry::class);

    $unloaded = Article::query()->findOrFail($owner->getKey());
    expect($entries['meta_title']->getLabel())->toBe('Meta title');
});

it('supports another owner relationship name without creating records during factory construction', function (): void {
    $owner = NewsCategory::query()->create(['name' => 'Category']);

    $section = SeoFields::make(relationship: 'seoMetadata');
    $infolist = SeoMetadataInfolistSection::make(relationship: 'seoMetadata');

    expect($section->getRelationshipName())->toBe('seoMetadata')
        ->and($infolist)->toBeInstanceOf(Section::class)
        ->and($owner->seoMetadata()->exists())->toBeFalse();
});

it('hydrates and persists the relationship through a rendered Livewire Filament schema', function (): void {
    $owner = Article::query()->create(['name' => 'Existing']);
    $metadata = $owner->seoMetadata()->create([
        'meta_title' => 'Old title',
        'meta_description' => 'Keep this description',
        'keywords' => 'existing, keywords',
        'og_title' => 'Preserved social title',
        'seo_image' => 'existing/seo.webp',
        'robots_index' => false,
        'robots_follow' => true,
    ]);

    Livewire::test(SeoFormHarness::class, ['recordId' => $owner->getKey()])
        ->assertSet('data.seoMetadata.meta_title', 'Old title')
        ->assertSet('data.seoMetadata.seo_image', fn (array $paths): bool => in_array('existing/seo.webp', $paths, true))
        ->set('data.seoMetadata.meta_title', 'Updated title')
        ->set('data.seoMetadata.robots_index', false)
        ->call('save');

    expect($metadata->fresh()->meta_title)->toBe('Updated title')
        ->and($metadata->fresh()->meta_description)->toBe('Keep this description')
        ->and($metadata->fresh()->keywords)->toBe('existing, keywords')
        ->and($metadata->fresh()->og_title)->toBe('Preserved social title')
        ->and($metadata->fresh()->seo_image)->toBe('existing/seo.webp')
        ->and($metadata->fresh()->robots_index)->toBeFalse()
        ->and(SeoMetadata::query()->where('seoable_id', $owner->getKey())->count())->toBe(1);

    $newForm = Livewire::test(SeoFormHarness::class);
    expect(SeoMetadata::query()->count())->toBe(1);

    $newForm
        ->set('data.seoMetadata.meta_title', 'Created through Filament')
        ->set('data.seoMetadata.meta_description', '')
        ->set('data.seoMetadata.robots_follow', false)
        ->call('save');

    $createdOwner = Article::query()->where('name', 'Harness')->latest('id')->firstOrFail();
    expect($createdOwner->seoMetadata()->count())->toBe(1)
        ->and($createdOwner->seoMetadata->meta_title)->toBe('Created through Filament')
        ->and($createdOwner->seoMetadata->meta_description)->toBeNull()
        ->and($createdOwner->seoMetadata->robots_follow)->toBeFalse();
});

it('does not create metadata on render and skips hidden or disabled editor saves', function (): void {
    $owner = Article::query()->create(['name' => 'Existing']);
    $metadata = $owner->seoMetadata()->create(['meta_title' => 'Original', 'meta_description' => 'Original description']);

    Livewire::test(SeoFormHarness::class, ['recordId' => $owner->getKey()]);
    expect($owner->seoMetadata()->count())->toBe(1);

    Livewire::test(SeoFormHarness::class, ['recordId' => $owner->getKey()])
        ->set('editorEnabled', false)
        ->set('data.seoMetadata.meta_title', 'Tampered disabled value')
        ->call('save');

    expect($metadata->fresh()->meta_title)->toBe('Original');

    Livewire::test(SeoFormHarness::class, ['recordId' => $owner->getKey()])
        ->set('editorVisible', false)
        ->set('data.seoMetadata.meta_title', 'Tampered hidden value')
        ->call('save');

    expect($metadata->fresh()->meta_title)->toBe('Original')
        ->and($owner->seoMetadata()->count())->toBe(1);
});

it('rejects image content that only claims an allowed image MIME type', function (): void {
    Storage::fake('public');
    config(['filament-seo.images.disk' => 'public']);

    $invalidImage = UploadedFile::fake()->create('not-an-image.jpg', 10)->mimeType('image/jpeg');
    file_put_contents($invalidImage->getPathname(), 'this is not an image');

    try {
        // Livewire test metadata otherwise supplies the claimed MIME type instead
        // of exercising the content sniffing used by a regular request.
        $this->app->detectEnvironment(static fn (): string => 'local');

        Livewire::test(SeoFormHarness::class)
            ->upload('data.seoMetadata.seo_image', [$invalidImage])
            ->call('save')
            ->assertHasErrors(['data.seoMetadata.seo_image']);

        expect(DB::table('seo_metadata')->whereNotNull('seo_image')->exists())->toBeFalse()
            ->and(Storage::disk('public')->allFiles())->toBeEmpty();
    } finally {
        $this->app->detectEnvironment(static fn (): string => 'testing');
        @unlink($invalidImage->getPathname());
    }
});

it('does not persist an SEO image when the host disables the relationship section', function (): void {
    Storage::fake('public');
    config(['filament-seo.images.disk' => 'public']);

    Livewire::test(SeoFormHarness::class)
        ->set('editorEnabled', false)
        ->upload('data.seoMetadata.seo_image', [UploadedFile::fake()->image('disabled.jpg')])
        ->call('save');

    expect(DB::table('seo_metadata')->whereNotNull('seo_image')->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});
