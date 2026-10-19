<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Filament\Resources\Pages;

use Digit7s\FilamentSeo\Filament\Resources\SeoPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSeoPages extends ListRecords
{
    protected static string $resource = SeoPageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
