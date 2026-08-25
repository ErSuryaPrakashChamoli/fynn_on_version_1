<?php

namespace App\Filament\Resources\LeadAssignmentReports;

use App\Filament\Resources\LeadAssignmentReports\Pages\ListLeadAssignmentReports;
use App\Filament\Resources\LeadAssignmentReports\Tables\LeadAssignmentReportsTable;
use App\Models\Employee;
use App\Support\HierarchyHelper;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class LeadAssignmentReportResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Lead Assignment';

    protected static ?string $navigationLabel = 'Monitoring & Reports';

    protected static ?string $modelLabel = 'Lead Assignment Report';

    protected static ?string $recordTitleAttribute = 'emp_name';

    public static function table(Table $table): Table
    {
        return LeadAssignmentReportsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->whereHas('assignmentsReceived');

        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('Admin')) {
            return $query;
        }

        return $query->whereIn('id', HierarchyHelper::visibleEmployeeIds($user));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeadAssignmentReports::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        $employee = $user->employee;

        if (! $employee) {
            return false;
        }

        return in_array($employee->designation, [
            Employee::DESIGNATION_CLUSTER,
            Employee::DESIGNATION_MANAGER,
            Employee::DESIGNATION_TEAM_LEADER,
        ]);
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
