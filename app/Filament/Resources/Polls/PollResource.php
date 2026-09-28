<?php

namespace App\Filament\Resources\Polls;

use App\Filament\Resources\Polls\Pages\CreatePoll;
use App\Filament\Resources\Polls\Pages\EditPoll;
use App\Filament\Resources\Polls\Pages\ListPolls;
use App\Filament\Resources\Polls\Pages\ViewPoll;
use App\Filament\Resources\Polls\RelationManagers\RecipientsRelationManager;
use App\Filament\Resources\Polls\RelationManagers\VotesRelationManager;
use App\Filament\Resources\Polls\Schemas\PollForm;
use App\Filament\Resources\Polls\Schemas\PollInfolist;
use App\Filament\Resources\Polls\Tables\PollsTable;
use App\Models\Poll;
use App\Models\User;
use App\Services\Voting\PollService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Setting → Voting: raise a vote or feedback request and follow its
 * results. Open to the Admin and every supervisor above caller level; the
 * Admin sees every poll, a raiser only their own.
 */
class PollResource extends Resource
{
    protected static ?string $model = Poll::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Setting';

    protected static ?string $navigationLabel = 'Voting & Feedback';

    protected static ?string $modelLabel = 'poll';

    protected static ?string $pluralModelLabel = 'polls';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return PollForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PollInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PollsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            VotesRelationManager::class,
            RecipientsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPolls::route('/'),
            'create' => CreatePoll::route('/create'),
            'view' => ViewPoll::route('/{record}'),
            'edit' => EditPoll::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['type', 'creator']);
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $user->hasRole('Admin') ? $query : $query->where('created_by', $user->getKey());
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(PollService::class)->canRaise($user);
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $record instanceof Poll && app(PollService::class)->canManage($record, $user);
    }

    public static function canEdit(Model $record): bool
    {
        return static::canView($record);
    }

    public static function canDelete(Model $record): bool
    {
        return (bool) Filament::auth()->user()?->hasRole('Admin');
    }
}
