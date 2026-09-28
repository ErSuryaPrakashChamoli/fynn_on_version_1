<?php

namespace App\Filament\Resources\Complaints;

use App\Enums\ComplaintStatus;
use App\Filament\Resources\Complaints\Pages\CreateComplaint;
use App\Filament\Resources\Complaints\Pages\EditComplaint;
use App\Filament\Resources\Complaints\Pages\ListComplaints;
use App\Filament\Resources\Complaints\Pages\ViewComplaint;
use App\Filament\Resources\Complaints\RelationManagers\CommentsRelationManager;
use App\Filament\Resources\Complaints\Schemas\ComplaintForm;
use App\Filament\Resources\Complaints\Schemas\ComplaintInfolist;
use App\Filament\Resources\Complaints\Tables\ComplaintsTable;
use App\Models\Complaint;
use App\Models\User;
use App\Services\HelpDesk\ComplaintService;
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
 * The Help Desk ticket queue. Open to every signed-in user whatever their
 * role: anyone raises a ticket; they see their own, handlers see their
 * team's queue and anything assigned or escalated to them, the Admin sees
 * everything. Every state change runs through ComplaintService.
 */
class ComplaintResource extends Resource
{
    protected static ?string $model = Complaint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Help Desk';

    protected static ?string $navigationLabel = 'Complaints & Tickets';

    protected static ?string $modelLabel = 'ticket';

    protected static ?string $pluralModelLabel = 'tickets';

    protected static ?string $recordTitleAttribute = 'subject';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return ComplaintForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ComplaintInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComplaintsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CommentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComplaints::route('/'),
            'create' => CreateComplaint::route('/create'),
            'view' => ViewComplaint::route('/{record}'),
            'edit' => EditComplaint::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['category', 'priority', 'raiser', 'beneficiary', 'assignee']);
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return app(ComplaintService::class)->scopeVisible($query, $user);
    }

    /** Every role in the system may raise and follow tickets. */
    public static function canAccess(): bool
    {
        return Filament::auth()->user() instanceof User;
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $record instanceof Complaint
            && app(ComplaintService::class)->canView($record, $user);
    }

    /** The raiser may fix the wording while nobody has picked it up; the Admin always can. */
    public static function canEdit(Model $record): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $record instanceof Complaint) {
            return false;
        }

        return $user->hasRole('Admin')
            || ($record->isRaisedBy($user) && $record->status === ComplaintStatus::Open);
    }

    public static function canDelete(Model $record): bool
    {
        return (bool) Filament::auth()->user()?->hasRole('Admin');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $open = static::getEloquentQuery()->unresolved()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
