<?php

namespace App\Filament\Resources\AccountVerifications;

use App\Filament\Resources\AccountVerifications\Pages\CreateAccountVerification;
use App\Filament\Resources\AccountVerifications\Pages\EditAccountVerification;
use App\Filament\Resources\AccountVerifications\Pages\ListAccountVerifications;
use App\Filament\Resources\AccountVerifications\Schemas\AccountVerificationForm;
use App\Filament\Resources\AccountVerifications\Tables\AccountVerificationsTable;
use App\Models\AccountVerification;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountVerificationResource extends Resource
{
    protected static ?string $model = AccountVerification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // protected static ?string $recordTitleAttribute = 'customer_name';

    protected static ?string $navigationLabel = 'Account Verification';

    // protected static ?string $navigationGroup = 'Accounts';

    // protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 1;


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
        return [
            //
        ];
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
            ->where('account_verified', false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->employee?->designation === \App\Models\Employee::DESIGNATION_ADMIN;
    }
}
