<?php

namespace App\Filament\Resources\CustomerReassignments;

use App\Filament\Resources\CustomerReassignments\Pages\ListCustomerReassignments;
use App\Filament\Resources\CustomerReassignments\Tables\CustomerReassignmentsTable;
use App\Models\Customer;
use App\Models\CustomerReassignment;
use App\Support\HierarchyHelper;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerReassignmentResource extends Resource
{
    protected static ?string $model = CustomerReassignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'Reassignments';

    protected static ?string $modelLabel = 'Customer Reassignment';

    protected static ?string $pluralModelLabel = 'Customer Reassignments';

    public static function table(Table $table): Table
    {
        return CustomerReassignmentsTable::configure($table);
    }

    /**
     * Admin sees every reassignment; anyone else those touching their own
     * branch — the customer, or either owner, sits inside it.
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

        $branchIds = HierarchyHelper::visibleSubordinateIds($employee);

        return $query->where(fn (Builder $query): Builder => $query
            ->whereIn('previous_owner_id', $branchIds)
            ->orWhereIn('new_owner_id', $branchIds)
            ->orWhereIn('customer_id', Customer::query()->whereIn('assign_to', $branchIds)->select('id')));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerReassignments::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['Admin', 'Cluster Manager', 'Business Head']);
    }
}
