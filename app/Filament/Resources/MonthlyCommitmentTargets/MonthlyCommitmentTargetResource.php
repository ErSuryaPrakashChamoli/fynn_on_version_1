<?php

namespace App\Filament\Resources\MonthlyCommitmentTargets;

use App\Filament\Resources\MonthlyCommitmentTargets\Pages\CreateMonthlyCommitmentTarget;
use App\Filament\Resources\MonthlyCommitmentTargets\Pages\EditMonthlyCommitmentTarget;
use App\Filament\Resources\MonthlyCommitmentTargets\Pages\ListMonthlyCommitmentTargets;
use App\Filament\Resources\MonthlyCommitmentTargets\Schemas\MonthlyCommitmentTargetForm;
use App\Filament\Resources\MonthlyCommitmentTargets\Tables\MonthlyCommitmentTargetsTable;
use App\Models\MonthlyCommitmentTarget;
use App\Services\DailyCommitmentService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
     * Targets are set by Admin and the management line, never by callers.
     */
    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->hasAnyRole([
            'Admin', 'Business Head', 'Cluster Manager', 'Manager', 'Team Leader',
        ]);
    }
}
