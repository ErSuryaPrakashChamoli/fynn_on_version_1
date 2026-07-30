<?php

namespace App\Filament\Resources\Teams\Tables;

use App\Filament\Resources\Teams\TeamResource;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

use App\Models\Employee;
use App\Support\HierarchyHelper;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use App\Services\IncentiveCalculator;


class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('emp_name')
                    ->searchable()
                    ->sortable(),

                //   TextColumn::make('designation')
                //     ->badge()
                //     ->formatStateUsing(fn (string $state): string => match ($state) {

                //         Employee::DESIGNATION_ADMIN => 'Admin',
                //         Employee::DESIGNATION_CLUSTER => 'Cluster Manager',
                //         Employee::DESIGNATION_MANAGER => 'Manager',
                //         Employee::DESIGNATION_TEAM_LEADER => 'Team Leader',
                //         Employee::DESIGNATION_CALLER => 'Caller',
                //         default => $state,
                //     }),

                // TextColumn::make('designation')
                // ->label('Designation')
                // ->formatStateUsing(fn ($state) => Employee::designationOptions()[$state] ?? '-')
                // ->sortable(),

                TextColumn::make('designation')
                    ->label('Position')
                    ->badge()
                    ->formatStateUsing(fn($state) => match ((string) $state) {
                        '1' => 'Admin',
                        '2' => 'Manager',
                        '3' => 'Team Leader',
                        '5' => 'Cluster Manager',
                        '7' => 'Caller',
                        default => 'Unknown',
                    }),


                TextColumn::make('superviser.emp_name')
                    ->label('Team Leader')
                    ->default('-'),

                TextColumn::make('manager.emp_name')
                    ->default('-'),

                TextColumn::make('cluster.emp_name')
                    ->label('Cluster Manager')
                    ->default('-'),

                TextColumn::make('target_category')
                    ->label('Target Category')
                    ->state(fn($record) => IncentiveCalculator::calculate($record)['target_category'])
                    ->badge()
                    ->color('info'),

                TextColumn::make('target')
                    ->label('Target')
                    ->state(fn($record) => IncentiveCalculator::calculate($record)['target'])
                    ->money('INR', divideBy: 100),

                TextColumn::make('actual')
                    ->label('Actual')
                    ->state(fn($record) => IncentiveCalculator::calculate($record)['actual'])
                    ->money('INR', divideBy: 100),

                TextColumn::make('cashback')
                    ->label('Cashback')
                    ->state(fn($record) => IncentiveCalculator::calculate($record)['cashback'])
                    ->money('INR', divideBy: 100),

                TextColumn::make('subvention')
                    ->label('Subvention')
                    ->state(fn($record) => IncentiveCalculator::calculate($record)['subvention'])
                    ->money('INR', divideBy: 100),

                TextColumn::make('docking')
                    ->label('Docking')
                    ->state(fn($record) => IncentiveCalculator::calculate($record)['docking'])
                    ->money('INR', divideBy: 100),

                TextColumn::make('count_achievement')
                    ->label('Count Achievement')
                    ->state(fn($record) => IncentiveCalculator::calculate($record)['count_achievement'])
                    ->money('INR', divideBy: 100),

                TextColumn::make('incentive')
                    ->label('Incentive')
                    ->state(fn($record) => IncentiveCalculator::calculate($record)['incentive'])
                    ->money('INR', divideBy: 100),
                //
            ])
            ->filters([
                //

                SelectFilter::make('designation')
                    ->options([
                        Employee::DESIGNATION_CLUSTER => 'Cluster Manager',
                        Employee::DESIGNATION_MANAGER => 'Manager',
                        Employee::DESIGNATION_TEAM_LEADER => 'Team Leader',
                        Employee::DESIGNATION_CALLER => 'Caller',
                    ]),

                SelectFilter::make('cluster_id')
                    ->label('Cluster Manager')
                    // ->relationship(
                    //     'cluster',
                    //     'emp_name',
                    //     fn ($query) => $query->whereIn(
                    //         'id',
                    //         HierarchyHelper::visibleEmployeeIds(auth()->user())
                    //     )
                    // )
                    ->relationship(
                        'superviser',
                        'emp_name',
                        fn($query) => $query->whereIn(
                            'id',
                            HierarchyHelper::visibleEmployeeIds(auth()->user())
                        )
                    )
                    ->searchable()
                    ->preload(),

                SelectFilter::make('manager_id')
                    ->label('Manager')
                    ->relationship(
                        'manager',
                        'emp_name',
                        fn($query) => $query->whereIn(
                            'id',
                            HierarchyHelper::visibleEmployeeIds(auth()->user())
                        )
                    )
                    ->searchable()
                    ->preload(),

                // SelectFilter::make('superviser_id')
                //     ->label('Team Leader')
                //     ->relationship(
                //         'supervisor',
                //         'emp_name',
                //         fn ($query) => $query->whereIn(
                //             'id',
                //             HierarchyHelper::visibleEmployeeIds(auth()->user())
                //         )
                //     )
                //     ->searchable()
                //     ->preload(),

            ])
            ->recordActions([
                // EditAction::make(),
                // Action::make('View Team')
                // ->icon('heroicon-o-eye')

                Action::make('View Team')
                    ->icon('heroicon-o-eye')
                    ->url(fn(Employee $record) => TeamResource::getUrl('view', [
                        'record' => $record,
                    ]))
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
