<?php

namespace App\Filament\Resources\CustomerPanRequests;

use App\Filament\Resources\CustomerPanRequests\Pages\CreateCustomerPanRequest;
use App\Filament\Resources\CustomerPanRequests\Pages\EditCustomerPanRequest;
use App\Filament\Resources\CustomerPanRequests\Pages\ListCustomerPanRequests;
use App\Filament\Resources\CustomerPanRequests\Schemas\CustomerPanRequestForm;
use App\Filament\Resources\CustomerPanRequests\Tables\CustomerPanRequestsTable;
use App\Models\CustomerPanRequest;
use App\Models\Employee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
// use continuation\ContinuePanRequest;
use UnitEnum;

class CustomerPanRequestResource extends Resource
{
    protected static ?string $model = CustomerPanRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'customer_id';

    protected static string|UnitEnum|null $navigationGroup = 'Request';

    protected static ?string $navigationLabel = 'Duplicate PAN Request';

    protected static ?string $modelLabel = 'PAN Request';

    public static function form(Schema $schema): Schema
    {
        return CustomerPanRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerPanRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Requests were previously visible to any authenticated user in full —
     * this scopes the listing by who raised the request and its snapshotted
     * approval chain (team_leader_id/manager_id/cluster_manager_id, set on
     * the request at creation time): Admin sees everything, a caller (or
     * any other non-hierarchy role) sees only requests they raised
     * themselves, and each of Team Leader/Manager/Cluster Manager sees the
     * requests that were made under them specifically.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('Admin')) {
            return $query;
        }

        $employee = $user->employee;

        if (! $employee) {
            return $query->whereRaw('1 = 0');
        }

        return match ($employee->designation) {
            Employee::DESIGNATION_CLUSTER => $query->where('cluster_manager_id', $employee->id),
            Employee::DESIGNATION_MANAGER => $query->where('manager_id', $employee->id),
            Employee::DESIGNATION_TEAM_LEADER => $query->where('team_leader_id', $employee->id),
            default => $query->where('requested_by', $employee->id),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerPanRequests::route('/'),
            'create' => CreateCustomerPanRequest::route('/create'),
            'edit' => EditCustomerPanRequest::route('/{record}/edit'),
            // 'continue-pan' => ContinuePanRequest::route(
            //     '/continue-pan/{request}'
            // ),
        ];
    }
}
