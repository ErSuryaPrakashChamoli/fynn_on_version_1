<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AssignedLeadFollowUpCalendarWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Leads → Lead Follow-up Outcomes: the lead-side follow-ups (assigned leads
 * and raw leads) by verdict, the same ones as the Lead Follow-Up Calendar
 * and the Dashboard calendar's Lead tab.
 */
class LeadFollowUpOutcomes extends FollowUpOutcomeListPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowUpRight;

    protected static string|UnitEnum|null $navigationGroup = 'Leads';

    protected static ?string $navigationLabel = 'Lead Follow-up Outcomes';

    protected static ?string $title = 'Lead Follow-up Outcomes';

    protected static ?string $slug = 'lead-follow-up-outcomes';

    protected static ?int $navigationSort = 4;

    protected function visibleFollowUpQuery(): Builder
    {
        return AssignedLeadFollowUpCalendarWidget::visibleLeadFollowUpQuery();
    }

    protected function recordSourceOptions(): array
    {
        return ['lead' => 'Lead', 'ai_record' => 'AI record'];
    }
}
