<?php

declare(strict_types=1);

namespace App\Filament\Resources\FlyerResource\Pages;

use App\Filament\Resources\FlyerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

final class EditFlyer extends EditRecord
{
    protected static string $resource = FlyerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
