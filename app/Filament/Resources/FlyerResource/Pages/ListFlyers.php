<?php

declare(strict_types=1);

namespace App\Filament\Resources\FlyerResource\Pages;

use App\Filament\Resources\FlyerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListFlyers extends ListRecords
{
    protected static string $resource = FlyerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
