<?php

declare(strict_types=1);

namespace App\Filament\Resources\RawFacebookPostResource\Pages;

use App\Filament\Resources\RawFacebookPostResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListRawFacebookPosts extends ListRecords
{
    protected static string $resource = RawFacebookPostResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
