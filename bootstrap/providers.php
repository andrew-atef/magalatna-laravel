<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\ViewServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ViewServiceProvider::class,
];
