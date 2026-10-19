<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Filament\Resources;

use BackedEnum;
use Digit7s\FilamentSeo\Filament\Forms\SeoFields;
use Digit7s\FilamentSeo\Filament\Infolists\SeoMetadataInfolistSection;
use Digit7s\FilamentSeo\Filament\Tables\SeoScoreColumn;
use Digit7s\FilamentSeo\Filament\Tables\SeoStatusColumn;
use Digit7s\FilamentSeo\Models\SeoPage;
use Digit7s\FilamentSeo\Support\SeoPagePath;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Throwable;
use UnitEnum;

/** Optional resource that a consumer explicitly registers in a Filament panel. */
class SeoPageResource extends Resource
{
    protected static ?string $model = SeoPage::class;

    protected static ?string $modelLabel = 'static SEO page';

    protected static ?string $pluralModelLabel = 'static SEO pages';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = static::$navigationGroup ?? config('filament-seo.static_pages.navigation.group', 'SEO');

        if ($group instanceof UnitEnum || $group === null) {
            return $group;
        }

        return is_string($group) && trim($group) !== '' ? __($group) : 'SEO';
    }

    public static function getNavigationLabel(): string
    {
        $label = static::$navigationLabel ?? config('filament-seo.static_pages.navigation.label', 'Static Pages');

        return is_string($label) && trim($label) !== '' ? __($label) : __('Static Pages');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        $icon = static::$navigationIcon ?? config('filament-seo.static_pages.navigation.icon', 'heroicon-o-globe-alt');

        return is_string($icon) || $icon instanceof BackedEnum || $icon instanceof Htmlable
            ? $icon
            : 'heroicon-o-globe-alt';
    }

    public static function getNavigationSort(): ?int
    {
        $sort = static::$navigationSort ?? config('filament-seo.static_pages.navigation.sort', 100);

        return $sort === null || is_int($sort) ? $sort : 100;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation()
            && config('filament-seo.static_pages.navigation.enabled', true) === true;
    }

    public static function canAccess(): bool
    {
        return self::isAuthorized('viewAny');
    }

    public static function canViewAny(): bool
    {
        return self::isAuthorized('viewAny');
    }

    public static function canCreate(): bool
    {
        return self::isAuthorized('create');
    }

    public static function canView(Model $record): bool
    {
        return self::isAuthorized('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return self::isAuthorized('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return self::isAuthorized('delete', $record);
    }

    public static function canDeleteAny(): bool
    {
        return self::isAuthorized('deleteAny');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Page Information')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('page_key')
                        ->required()
                        ->maxLength(120)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->unique(ignoreRecord: true)
                        ->helperText('Stable identifier used with <x-filament-seo::meta page="about-us" />.'),
                    TextInput::make('route_name')->nullable()->maxLength(255)->unique(ignoreRecord: true),
                    TextInput::make('path')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Exact URL path. Query strings and fragments are ignored; the root page uses /.')
                        ->rules([static function (?Model $record): \Closure {
                            return static function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                try {
                                    $path = SeoPagePath::normalize((string) $value);
                                } catch (Throwable) {
                                    $fail('Enter a relative URL path, such as /about-us.');

                                    return;
                                }

                                $exists = SeoPage::query()
                                    ->where('path', $path)
                                    ->when($record?->exists, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($exists) {
                                    $fail('This URL path is already registered.');
                                }
                            };
                        }])
                        ->dehydrateStateUsing(fn (?string $state): string => SeoPagePath::normalize($state ?? '')),
                    Toggle::make('is_active')->default(true),
                ]),
            SeoFields::make(relationship: 'seoMetadata', groups: ['basic', 'social', 'robots', 'structured_data'], tabbed: true),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Page Information')->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('page_key'),
                TextEntry::make('route_name')->placeholder('No named route'),
                TextEntry::make('path'),
                TextEntry::make('is_active')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
            ]),
            SeoMetadataInfolistSection::make(
                relationship: 'seoMetadata',
                showResolvedValues: true,
                showCompleteness: true,
                showScore: true,
            ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('page_key')->searchable()->copyable(),
                TextColumn::make('path')->searchable()->copyable(),
                SeoStatusColumn::make(relationship: 'seoMetadata', issuesTooltip: true),
                SeoScoreColumn::make(relationship: 'seoMetadata'),
                TextColumn::make('is_active')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Active')])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    /** @return Builder<SeoPage> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('seoMetadata');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeoPages::route('/'),
            'create' => Pages\CreateSeoPage::route('/create'),
            'view' => Pages\ViewSeoPage::route('/{record}'),
            'edit' => Pages\EditSeoPage::route('/{record}/edit'),
        ];
    }

    private static function hasAuthenticatedUser(): bool
    {
        return Filament::auth()->check();
    }

    private static function hasAuthorizationRule(string $ability): bool
    {
        $policy = Gate::getPolicyFor(SeoPage::class);

        return Gate::has($ability) || ($policy !== null && method_exists($policy, $ability));
    }

    private static function isAuthorized(string $ability, ?Model $record = null): bool
    {
        if (! self::hasAuthenticatedUser() || ! self::hasAuthorizationRule($ability)) {
            return false;
        }

        return Gate::forUser(Filament::auth()->user())->allows($ability, $record ?? SeoPage::class);
    }
}
