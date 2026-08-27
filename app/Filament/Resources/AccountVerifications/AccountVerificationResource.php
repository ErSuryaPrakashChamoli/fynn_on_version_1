<?php

namespace App\Filament\Resources\AccountVerifications;

use App\Filament\Resources\AccountVerifications\Pages\CreateAccountVerification;
use App\Filament\Resources\AccountVerifications\Pages\EditAccountVerification;
use App\Filament\Resources\AccountVerifications\Pages\ListAccountVerifications;
use App\Filament\Resources\AccountVerifications\Schemas\AccountVerificationForm;
use App\Filament\Resources\AccountVerifications\Tables\AccountVerificationsTable;
use App\Models\Customer;
use App\Support\SelectedMonth;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AccountVerificationResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationLabel = 'MIS Verification';

    protected static ?string $recordTitleAttribute = 'customer_name';

    protected static ?int $navigationSort = 1;

    protected static string|UnitEnum|null $navigationGroup = 'Accounts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function form(Schema $schema): Schema
    {
        return AccountVerificationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountVerificationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountVerifications::route('/'),
            'create' => CreateAccountVerification::route('/create'),
            'edit' => EditAccountVerification::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('journey_status', 'disbursed')
            ->whereBetween('updated_at', SelectedMonth::range())
            ->with(['employee', 'settlement']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['Admin', 'MIS']) ?? false;
    }
}
