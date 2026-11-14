<?php

namespace App\Filament\Resources\EmployeePerformanceReports\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\EmployeePerformanceReports\EmployeePerformanceReportResource;
use Filament\Resources\Pages\ListRecords;

class ListEmployeePerformanceReports extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = EmployeePerformanceReportResource::class;
}
