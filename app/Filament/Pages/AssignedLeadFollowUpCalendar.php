<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AssignedLeadFollowUpCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Leads';

    protected static ?string $navigationLabel = 'Lead Follow-Up Calendar';

    protected static ?string $title = 'Assigned Lead Follow-Up Calendar';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.assigned-lead-follow-up-calendar';
}
