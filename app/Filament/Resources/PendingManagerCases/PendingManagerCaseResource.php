<?php

namespace App\Filament\Resources\PendingManagerCases;

use App\Filament\Resources\PendingManagerCases\Pages\ListPendingManagerCases;
use App\Filament\Resources\PendingManagerCases\Tables\PendingManagerCasesTable;
use App\Models\Customer;
use App\Services\Journey\JourneySlaService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only oversight queue: every customer currently in flight (not yet
 * disbursed) with its resolved Manager-stage module, the natural Manager,
 * how long it has been waiting, and SLA status. This is NOT a duplicate of
 * "My Customers" — it is the Customer Journey Continuity module's
 * cross-hierarchy view for the authorized users who need to spot and act
 * on stuck cases, not the Customer Journey itself.
 */
class PendingManagerCaseResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Pending Manager Cases';

    protected static ?string $modelLabel = 'Pending Manager Case';

    protected static ?string $pluralModelLabel = 'Pending Manager Cases';

    public static function table(Table $table): Table
    {
        return PendingManagerCasesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return JourneySlaService::activeCustomersQuery();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPendingManagerCases::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['Admin', 'Cluster Manager', 'Business Head']);
    }
}
