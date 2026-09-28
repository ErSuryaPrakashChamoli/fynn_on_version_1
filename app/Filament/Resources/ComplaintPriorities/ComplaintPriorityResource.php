<?php

namespace App\Filament\Resources\ComplaintPriorities;

use App\Filament\Resources\ComplaintPriorities\Pages\CreateComplaintPriority;
use App\Filament\Resources\ComplaintPriorities\Pages\EditComplaintPriority;
use App\Filament\Resources\ComplaintPriorities\Pages\ListComplaintPriorities;
use App\Filament\Resources\ComplaintPriorities\Schemas\ComplaintPriorityForm;
use App\Filament\Resources\ComplaintPriorities\Tables\ComplaintPrioritiesTable;
use App\Models\ComplaintPriority;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin control of the Help Desk SLA: the priorities a ticket can carry
 * and how many hours each one gives the handlers before it escalates.
 */
class ComplaintPriorityResource extends Resource
{
    protected static ?string $model = ComplaintPriority::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Help Desk';

    protected static ?string $navigationLabel = 'Priorities & SLA';

    protected static ?string $modelLabel = 'priority';

    protected static ?string $pluralModelLabel = 'priorities';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return ComplaintPriorityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComplaintPrioritiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComplaintPriorities::route('/'),
            'create' => CreateComplaintPriority::route('/create'),
            'edit' => EditComplaintPriority::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->hasRole('Admin');
    }

    /** A priority with tickets on it is switched off, not deleted. */
    public static function canDelete(Model $record): bool
    {
        return static::canAccess()
            && $record instanceof ComplaintPriority
            && ! $record->complaints()->exists();
    }
}
