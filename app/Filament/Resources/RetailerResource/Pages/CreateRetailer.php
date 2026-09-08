<?php

declare(strict_types=1);

namespace App\Filament\Resources\RetailerResource\Pages;

use App\Filament\Resources\RetailerResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateRetailer extends CreateRecord
{
    protected static string $resource = RetailerResource::class;
}
