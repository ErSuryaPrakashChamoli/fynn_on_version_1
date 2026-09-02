<?php

namespace App\Filament\Resources\CustomerSettlements;

use App\Filament\Resources\CustomerSettlements\Pages\CreateCustomerSettlement;
use App\Filament\Resources\CustomerSettlements\Pages\EditCustomerSettlement;
use App\Filament\Resources\CustomerSettlements\Pages\ListCustomerSettlements;
use App\Filament\Resources\CustomerSettlements\RelationManagers\SettlementHistoryRelationManager;
use App\Filament\Resources\CustomerSettlements\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\CustomerSettlements\Schemas\CustomerSettlementForm;
use App\Filament\Resources\CustomerSettlements\Tables\CustomerSettlementsTable;
use App\Models\CustomerSettlement;
use App\Support\SelectedMonth;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CustomerSettlementResource extends Resource
{
    protected static ?string $model = CustomerSettlement::class;

    protected static ?string $navigationLabel = 'Customer Settlement';

    protected static ?string $recordTitleAttribute = 'settlement_no';

    protected static string|UnitEnum|null $navigationGroup = 'Accounts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    private const ACCOUNTS_STATUSES = [
        'mis_verified',
        'accounts_review',
        'variance',
        'payment_pending',
        'partially_paid',
        'paid',
        'recovery_pending',
        'settled',
        'hold',
    ];

    public static function form(Schema $schema): Schema
    {
        return CustomerSettlementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerSettlementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TransactionsRelationManager::class,
            SettlementHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerSettlements::route('/'),
            'create' => CreateCustomerSettlement::route('/create'),
            'edit' => EditCustomerSettlement::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->whereHas(
                'customer',
                fn (Builder $q) => $q->whereBetween('disbursal_date', SelectedMonth::range())
            );

        if (auth()->user()?->hasRole('Admin')) {
            return $query;
        }

        if (auth()->user()?->hasRole('Accounts')) {
            return $query->whereIn('status', self::ACCOUNTS_STATUSES);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['Admin', 'Accounts']) ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        if (auth()->user()?->hasRole('Admin')) {
            return true;
        }

        return auth()->user()?->hasRole('Accounts')
            && in_array($record->status, self::ACCOUNTS_STATUSES, true);
    }

    public static function canEdit(Model $record): bool
    {
        if (auth()->user()?->hasRole('Admin')) {
            return true;
        }

        return auth()->user()?->hasRole('Accounts')
            && in_array($record->status, self::ACCOUNTS_STATUSES, true);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
