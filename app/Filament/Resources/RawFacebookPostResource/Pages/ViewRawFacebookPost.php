<?php

declare(strict_types=1);

namespace App\Filament\Resources\RawFacebookPostResource\Pages;

use App\Filament\Resources\RawFacebookPostResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

final class ViewRawFacebookPost extends ViewRecord
{
    protected static string $resource = RawFacebookPostResource::class;
}
