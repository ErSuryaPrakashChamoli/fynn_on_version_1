<?php

namespace App\Filament\Resources\CustomerSlaBreaches;

use App\Filament\Resources\CustomerSlaBreaches\Pages\ListCustomerSlaBreaches;
use App\Filament\Resources\CustomerSlaBreaches\Tables\CustomerSlaBreachesTable;
use App\Models\Customer;
use App\Models\CustomerSlaBreach;
use App\Support\HierarchyHelper;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerSlaBreachResource extends Resource
{
    protected static ?string $model = CustomerSlaBreach::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'SLA Breaches';

    protected static ?string $modelLabel = 'SLA Breach';

    protected static ?string $pluralModelLabel = 'SLA Breaches';

    public static function table(Table $table): Table
    {
        return CustomerSlaBreachesTable::configure($table);
    }

    /**
     * Admin sees every breach; anyone else the breaches on customers owned
     * inside their own branch, plus any escalated to them personally.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->hasRole('Admin')) {
            return $query;
        }

        $employee = $user?->employee;

        if (! $employee) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $query): Builder => $query
            ->whereIn('customer_id', Customer::query()
                ->whereIn('assign_to', HierarchyHelper::visibleSubordinateIds($employee))
                ->select('id'))
            ->orWhere('escalated_to_employee_id', $employee->id));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerSlaBreaches::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['Admin', 'Cluster Manager', 'Business Head']);
    }
}
