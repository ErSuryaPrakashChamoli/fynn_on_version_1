<?php

namespace App\Filament\Resources\PollTypes;

use App\Filament\Resources\PollTypes\Pages\CreatePollType;
use App\Filament\Resources\PollTypes\Pages\EditPollType;
use App\Filament\Resources\PollTypes\Pages\ListPollTypes;
use App\Filament\Resources\PollTypes\Schemas\PollTypeForm;
use App\Filament\Resources\PollTypes\Tables\PollTypesTable;
use App\Models\PollType;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Setting → Poll Types: the Admin-only definition of what a poll can ask
 * (Good / Satisfactory / Bad, Yes / No, ...). Raisers pick from these.
 */
class PollTypeResource extends Resource
{
    protected static ?string $model = PollType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Setting';

    protected static ?string $navigationLabel = 'Poll Types';

    protected static ?string $modelLabel = 'poll type';

    protected static ?string $pluralModelLabel = 'poll types';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return PollTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PollTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPollTypes::route('/'),
            'create' => CreatePollType::route('/create'),
            'edit' => EditPollType::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->hasRole('Admin');
    }

    /** A type with polls behind it is switched off, not deleted. */
    public static function canDelete(Model $record): bool
    {
        return static::canAccess()
            && $record instanceof PollType
            && ! $record->polls()->exists();
    }
}
