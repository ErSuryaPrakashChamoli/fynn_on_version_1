<?php

namespace App\Filament\Resources\LeadAssignmentReports\Pages;

use App\Filament\Resources\LeadAssignmentReports\LeadAssignmentReportResource;
use Filament\Resources\Pages\ListRecords;

class ListLeadAssignmentReports extends ListRecords
{
    protected static string $resource = LeadAssignmentReportResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
