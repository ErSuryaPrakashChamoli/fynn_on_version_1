<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CustomerFollowUpCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Follow-ups';

    protected static ?string $navigationLabel = 'My Customer Follow-up Calendar';

    protected static ?string $title = 'My Customer Follow-up Calendar';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.customer-follow-up-calendar';
}
