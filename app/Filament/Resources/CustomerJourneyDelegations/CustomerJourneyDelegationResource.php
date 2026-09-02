<?php

namespace App\Filament\Resources\CustomerJourneyDelegations;

use App\Filament\Resources\CustomerJourneyDelegations\Pages\ListCustomerJourneyDelegations;
use App\Filament\Resources\CustomerJourneyDelegations\Tables\CustomerJourneyDelegationsTable;
use App\Models\CustomerJourneyDelegation;
use App\Support\HierarchyHelper;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Team Continuity / Backup Access" — the submodule that grants temporary
 * operational access when an employee at ANY hierarchy level (Caller,
 * Team Leader, Manager, Cluster Manager, Business Head) is unavailable.
 * Despite the table/class name (kept to avoid a disruptive rename of an
 * already-referenced table), this is no longer Manager-only — see
 * CustomerJourneyDelegationService for the generalized validation.
 */
class CustomerJourneyDelegationResource extends Resource
{
    protected static ?string $model = CustomerJourneyDelegation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Team Continuity / Backup Access';

    protected static ?string $modelLabel = 'Continuity Assignment';

    protected static ?string $pluralModelLabel = 'Team Continuity / Backup Access';

    public static function table(Table $table): Table
    {
        return CustomerJourneyDelegationsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user->hasAnyRole(['Admin', 'Business Head'])) {
            return $query;
        }

        $employee = $user->employee;

        if (! $employee) {
            return $query->whereRaw('1 = 0');
        }

        // Visible when the viewer is a direct participant (original or
        // backup), created the rule themselves, or has oversight of the
        // original employee (their own subordinate branch) — so a Cluster
        // Manager can see continuity rules covering any Manager beneath
        // them, not just ones they personally authored.
        $oversightIds = HierarchyHelper::subordinateIds($employee);

        return $query->where(function (Builder $query) use ($employee, $oversightIds) {
            $query->where('delegating_manager_id', $employee->id)
                ->orWhere('acting_manager_id', $employee->id)
                ->orWhere('created_by', auth()->id())
                ->orWhereIn('delegating_manager_id', $oversightIds);
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerJourneyDelegations::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user->hasAnyRole(['Admin', 'Business Head'])) {
            return true;
        }

        // Every hierarchy level can view/manage continuity for themselves
        // or their own branch (section 14: "applicable to all levels") —
        // the service layer still enforces exactly who may nominate whom.
        return $user->employee !== null;
    }
}
