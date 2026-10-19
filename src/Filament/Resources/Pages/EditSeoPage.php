<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Filament\Resources\Pages;

use Digit7s\FilamentSeo\Filament\Resources\SeoPageResource;
use Filament\Resources\Pages\EditRecord;

class EditSeoPage extends EditRecord
{
    protected static string $resource = SeoPageResource::class;
}
