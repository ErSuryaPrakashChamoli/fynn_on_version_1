<?php

namespace App\Filament\Pages;

use App\Filament\Resources\FollowUps\FollowUpResource;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Customers → Customer Follow-up Outcomes: the customer follow-ups by
 * verdict, the same ones as My Customer Follow-ups, the Customer Follow-up
 * Calendar and the Dashboard calendar's Customer tab.
 */
class CustomerFollowUpOutcomes extends FollowUpOutcomeListPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowDownLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Follow-ups';

    protected static ?string $navigationLabel = 'Customer Follow-up Outcomes';

    protected static ?string $title = 'Customer Follow-up Outcomes';

    protected static ?string $slug = 'customer-follow-up-outcomes';

    protected static ?int $navigationSort = 3;

    protected function visibleFollowUpQuery(): Builder
    {
        return FollowUpResource::getEloquentQuery();
    }
}
