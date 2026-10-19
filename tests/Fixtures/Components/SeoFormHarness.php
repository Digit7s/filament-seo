<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Tests\Fixtures\Components;

use Digit7s\FilamentSeo\Filament\Forms\SeoFields;
use Digit7s\FilamentSeo\Tests\Fixtures\Models\Article;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

final class SeoFormHarness extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public ?Article $record = null;

    public string $name = 'Harness';

    public array $data = [];

    public bool $editorEnabled = true;

    public bool $editorVisible = true;

    public bool $tabbed = false;

    public bool $withStructuredData = false;

    public bool $requireSocialTitle = false;

    public function mount(?int $recordId = null): void
    {
        $this->record = $recordId ? Article::query()->findOrFail($recordId) : new Article;
        $this->schema->fill();
        $this->schema->loadStateFromRelationships(shouldHydrate: true);
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([SeoFields::make(
                disabled: fn (self $livewire): bool => ! $livewire->editorEnabled,
                visible: fn (self $livewire): bool => $livewire->editorVisible,
                groups: $this->withStructuredData
                    ? ['basic', 'social', 'robots', 'structured_data']
                    : ['basic', 'social', 'robots'],
                required: $this->requireSocialTitle ? ['og_title' => true] : [],
                tabbed: $this->tabbed,
            )])
            ->model($this->record)
            ->statePath('data');
    }

    public function save(): void
    {
        $this->schema->getState(shouldCallHooksBefore: false);
        $this->record->name = $this->name;
        $this->record->save();
        $this->schema->model($this->record)->saveRelationships();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
