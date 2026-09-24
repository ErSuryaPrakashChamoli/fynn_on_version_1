<?php

namespace App\Filament\Resources\LeadAssignmentReports\Pages;

use App\Filament\Resources\LeadAssignmentReports\LeadAssignmentReportResource;
use App\Filament\Resources\LeadAssignmentReports\Widgets\LeadAssignmentSummary;
use App\Support\SelectedMonth;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;

class ListLeadAssignmentReports extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = LeadAssignmentReportResource::class;

    public function getSubheading(): ?string
    {
        return filled(data_get($this->tableFilters, 'assigned_on.assigned_from')) || filled(data_get($this->tableFilters, 'assigned_on.assigned_until'))
            ? 'Leads assigned in the chosen "Assigned On" range — who holds them, who is working them, and what to do next.'
            : 'Leads assigned in '.SelectedMonth::label().' — who holds them, who is working them, and what to do next. Use the "Assigned On" filter for any other range.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            LeadAssignmentSummary::class,
        ];
    }
}
