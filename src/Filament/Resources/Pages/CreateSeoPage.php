<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Filament\Resources\Pages;

use Digit7s\FilamentSeo\Filament\Resources\SeoPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSeoPage extends CreateRecord
{
    protected static string $resource = SeoPageResource::class;
}
