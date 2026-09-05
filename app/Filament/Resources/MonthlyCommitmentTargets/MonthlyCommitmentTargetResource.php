<?php

namespace App\Filament\Resources\MonthlyCommitmentTargets;

use App\Filament\Resources\MonthlyCommitmentTargets\Pages\CreateMonthlyCommitmentTarget;
use App\Filament\Resources\MonthlyCommitmentTargets\Pages\EditMonthlyCommitmentTarget;
use App\Filament\Resources\MonthlyCommitmentTargets\Pages\ListMonthlyCommitmentTargets;
use App\Filament\Resources\MonthlyCommitmentTargets\Schemas\MonthlyCommitmentTargetForm;
use App\Filament\Resources\MonthlyCommitmentTargets\Tables\MonthlyCommitmentTargetsTable;
use App\Models\MonthlyCommitmentTarget;
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
 * Manual monthly targets for the Daily Commitment module. Deliberately
 * separate from the LMS's own target/incentive engine — nothing here is
 * read by AchievementCalculatorService.
 */
class MonthlyCommitmentTargetResource extends Resource
{
    protected static ?string $model = MonthlyCommitmentTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Daily Commitment';

    protected static ?string $navigationLabel = 'Monthly Target';

    protected static ?string $modelLabel = 'monthly target';

    protected static ?string $pluralModelLabel = 'monthly targets';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return MonthlyCommitmentTargetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MonthlyCommitmentTargetsTable::configure($table);
    }

    /**
     * Server-side scoping: a manager only ever sees targets for people in
     * their own reporting tree, Admin sees everyone.
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
            'index' => ListMonthlyCommitmentTargets::route('/'),
            'create' => CreateMonthlyCommitmentTarget::route('/create'),
            'edit' => EditMonthlyCommitmentTarget::route('/{record}/edit'),
        ];
    }

    /**
     * Only somebody who actually owns a target can open this screen. A
     * Manager owns their callers, the Admin line (Admin / Business Head,
     * and a Cluster Manager inside their own branch) owns Managers and
     * Team Leaders. A Team Leader or Caller owns nobody — they wait for
     * theirs to be fixed, and are told so by the monthly target prompt.
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
        return static::canTouch($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canTouch($record);
    }

    /**
     * A target may only be changed by whoever is allowed to set it — a
     * Manager can see a Team Leader's target row on the listing but must
     * not be able to rewrite it.
     */
    protected static function canTouch(Model $record): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && app(MonthlyTargetGate::class)->canSetTargetFor($user, (int) $record->employee_id);
    }
}
