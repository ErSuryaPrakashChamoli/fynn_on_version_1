<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AcademyPanelProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\DemoPanelProvider;
use App\Providers\PortalServiceProvider;

return [
    AppServiceProvider::class,
    PortalServiceProvider::class,
    AdminPanelProvider::class,
    AcademyPanelProvider::class,
    DemoPanelProvider::class,
];
