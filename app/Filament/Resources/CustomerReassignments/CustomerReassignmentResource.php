<?php

namespace App\Filament\Resources\CustomerReassignments;

use App\Filament\Resources\CustomerReassignments\Pages\ListCustomerReassignments;
use App\Filament\Resources\CustomerReassignments\Tables\CustomerReassignmentsTable;
use App\Models\CustomerReassignment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

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
