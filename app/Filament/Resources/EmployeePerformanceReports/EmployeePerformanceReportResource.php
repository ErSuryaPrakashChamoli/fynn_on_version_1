<?php

namespace App\Filament\Resources\EmployeePerformanceReports;

use App\Filament\Resources\EmployeePerformanceReports\Pages\ListEmployeePerformanceReports;
use App\Filament\Resources\EmployeePerformanceReports\Tables\EmployeePerformanceReportsTable;
use App\Models\Employee;
use App\Support\HierarchyHelper;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmployeePerformanceReportResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'All Employees';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Performance Report';

    protected static ?string $recordTitleAttribute = 'emp_name';

    public static function table(Table $table): Table
    {
        return EmployeePerformanceReportsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', HierarchyHelper::visibleEmployeeIds($user));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeePerformanceReports::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
