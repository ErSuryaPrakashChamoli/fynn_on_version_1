<?php

namespace App\Filament\Resources\UserLoginSessions;

use App\Filament\Resources\UserLoginSessions\Pages\ListUserLoginSessions;
use App\Filament\Resources\UserLoginSessions\Pages\ViewUserLoginSession;
use App\Filament\Resources\UserLoginSessions\Tables\UserLoginSessionsTable;
use App\Models\UserLoginSession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

use App\Filament\Resources\UserLoginSessions\Schemas\UserLoginSessionInfolist;
use Filament\Schemas\Schema;

class UserLoginSessionResource extends Resource
{
    protected static ?string $model = UserLoginSession::class;

    protected static string|BackedEnum|null $navigationIcon =
    Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Login & Screen Time';

    protected static ?string $modelLabel = 'Login Session';

    protected static ?string $pluralModelLabel = 'Login & Screen Time';

    protected static ?int $navigationSort = 90;

    public static function table(Table $table): Table
    {
        return UserLoginSessionsTable::configure($table);
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
            'index' => ListUserLoginSessions::route('/'),
            'view' => ViewUserLoginSession::route('/{record}'),
        ];
    }

    /**
     * Login history should not be manually created or edited.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    /**
     * Initially only Admin can see the module.
     */
    // public static function shouldRegisterNavigation(): bool
    // {
    //     return auth()->check()
    //         && auth()->user()->hasRole('Admin');
    // }

    public static function infolist(Schema $schema): Schema
    {
        return UserLoginSessionInfolist::configure($schema);
    }
}
