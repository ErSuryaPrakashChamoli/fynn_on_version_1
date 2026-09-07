<?php

namespace App\Filament\Resources\EmployeeInactivityRequests;

use App\Filament\Resources\EmployeeInactivityRequests\Pages\CreateEmployeeInactivityRequest;
use App\Filament\Resources\EmployeeInactivityRequests\Pages\ListEmployeeInactivityRequests;
use App\Filament\Resources\EmployeeInactivityRequests\Schemas\EmployeeInactivityRequestForm;
use App\Filament\Resources\EmployeeInactivityRequests\Tables\EmployeeInactivityRequestsTable;
use App\Models\EmployeeInactivityRequest;
use App\Models\User;
use App\Services\DailyCommitmentService;
use App\Services\MonthlyTargetGate;
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
 * The inactivity tickets raised from the Daily Commitment module: a
 * Manager says somebody on their team has gone inactive, that person's
 * monthly commitment target is skipped from that moment, and the Admin
 * line reviews the ticket here.
 *
 * Approving is what actually takes the employee off the rolls; rejecting
 * puts their target back and the month blocks again until it is fixed.
 */
class EmployeeInactivityRequestResource extends Resource
{
    protected static ?string $model = EmployeeInactivityRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserMinus;

    protected static string|UnitEnum|null $navigationGroup = 'Daily Commitment';

    protected static ?string $navigationLabel = 'Inactivity Tickets';

    protected static ?string $modelLabel = 'inactivity ticket';

    protected static ?string $pluralModelLabel = 'inactivity tickets';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return EmployeeInactivityRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeInactivityRequestsTable::configure($table);
    }

    /**
     * Same scoping as the rest of the module: a Manager only ever sees
     * tickets for people in their own reporting tree, Admin sees all.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn(
                'employee_id',
                app(DailyCommitmentService::class)->visibleEmployeeIds(Filament::auth()->user()),
            );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeInactivityRequests::route('/'),
            'create' => CreateEmployeeInactivityRequest::route('/create'),
        ];
    }

    /**
     * Whoever sets targets raises the tickets — a Manager for their
     * callers, the Admin line and a Cluster Manager above them.
     */
    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && app(MonthlyTargetGate::class)->isTargetSetter($user);
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // A raised ticket is the audit trail for a skipped target. Only
        // the Admin line may withdraw one, and only while it is pending.
        return static::isReviewer()
            && $record instanceof EmployeeInactivityRequest
            && $record->isPending();
    }

    /** Only the Admin line approves or rejects a ticket. */
    public static function isReviewer(): bool
    {
        return (bool) Filament::auth()->user()?->hasAnyRole(['Admin', 'Business Head']);
    }
}
