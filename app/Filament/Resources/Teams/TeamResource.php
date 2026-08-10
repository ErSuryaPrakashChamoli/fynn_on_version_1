<?php

namespace App\Filament\Resources\Teams;

use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\Schemas\TeamForm;
use App\Filament\Resources\Teams\Tables\TeamsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;


use App\Models\Employee;
use App\Support\HierarchyHelper;
use Illuminate\Database\Eloquent\Builder;
use App\Services\AchievementCalculatorService;




class TeamResource extends Resource
{

    protected static ?string $model = Employee::class;

    protected static ?string $navigationLabel = 'Teams';

    protected static ?string $modelLabel = 'Team';

    protected static ?string $pluralModelLabel = 'Teams';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;


    public static function form(Schema $schema): Schema
    {
        // return TeamForm::configure($schema);
        return $schema;
    }

    public static function table(Table $table): Table
    {

        $calculator = app(AchievementCalculatorService::class);

        $performanceCache = [];

        return TeamsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }



    public static function getEloquentQuery(): Builder
    {
        // dd("called");
        return HierarchyHelper::directReportees(auth()->user());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
            'view' => Pages\ViewTeam::route('/{record}'),
            'view-team' => Pages\ViewTeam::route('/{record}/view-team'),
            'view-customers' => Pages\CustomerTeamPage::route('/{record}/customers'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Administration';
    }


    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user->hasRole('Admin')) {
            return true;
        }

        $employee = $user->employee;

        if (! $employee) {
            return false;
        }

        return in_array($employee->designation, [
            Employee::DESIGNATION_CLUSTER,
            Employee::DESIGNATION_MANAGER,
            Employee::DESIGNATION_TEAM_LEADER,
        ]);
    }
}
