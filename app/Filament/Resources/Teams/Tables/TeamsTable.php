<?php

namespace App\Filament\Resources\Teams\Tables;

use App\Filament\Resources\Teams\TeamResource;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use App\Support\EmployeeOptions;
use App\Support\HierarchyHelper;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TeamsTable
{
    public static function configure(Table $table): Table
    {

        $calculator = app(AchievementCalculatorService::class);
        $performanceCache = [];
        $eligibleCallerCache = [];

        return $table
            ->columns([

                TextColumn::make('emp_name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('emp_id')
                    ->label('Emp ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('designation')
                    ->label('Position')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(
                        fn ($state) => Employee::designationOptions()[$state] ?? 'Unknown'
                    )
                    ->color(fn ($state) => match ((int) $state) {
                        Employee::DESIGNATION_CLUSTER => 'primary',
                        Employee::DESIGNATION_MANAGER => 'success',
                        Employee::DESIGNATION_TEAM_LEADER => 'warning',
                        Employee::DESIGNATION_CALLER => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('superviser.emp_name')
                    ->label('Team Leader')
                    ->default('-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('manager.emp_name')
                    ->default('-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cluster.emp_name')
                    ->label('Cluster Manager')
                    ->default('-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('target_category')
                    ->label('Category')
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['target_category'];
                    }),

                // TextColumn::make('target_category')
                //     ->label('Category')
                //     ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                //         if ($record->designation !== Employee::DESIGNATION_CALLER) {
                //             return null;
                //         }

                //         $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                //         return $performanceCache[$record->id]['target_category'];
                //     }),

                TextColumn::make('target')
                    ->label('Target')
                    ->alignEnd()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        /*
                        |--------------------------------------------------------------------------
                        | Caller
                        |--------------------------------------------------------------------------
                        |
                        | The Caller himself sees his flat category target. Anyone above
                        | him in the hierarchy sees the entry/exit-adjusted target
                        | instead (matches the Team View listing), via the canonical
                        | engine — not a hand-rolled date check.
                        |
                        */

                        if ($record->designation === Employee::DESIGNATION_CALLER) {

                            if (auth()->user()?->employee?->id === $record->id) {
                                return $calculator->getTarget($record);
                            }

                            return $calculator->getHierarchyCallerTarget($record);
                        }

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['target'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('actual')
                    ->label('Actual')
                    ->alignEnd()
                    ->color('success')
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['actual'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('count_achievement')
                    ->label('Count Achievement')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['count_achievement'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('eligible_callers')
                    ->label('Eligible Callers')
                    ->alignCenter()
                    ->state(function (Employee $record) use ($calculator, &$eligibleCallerCache) {

                        if ($record->designation === Employee::DESIGNATION_CALLER) {
                            return null;
                        }

                        $eligibleCallerCache[$record->id] ??= $calculator->getEligibleCallerCount($record);

                        return $eligibleCallerCache[$record->id];
                    })
                    ->placeholder('-'),

                TextColumn::make('ppp')
                    ->label('PPP')
                    ->alignEnd()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache, &$eligibleCallerCache) {

                        if ($record->designation === Employee::DESIGNATION_CALLER) {
                            return null;
                        }

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        $eligibleCallerCache[$record->id] ??= $calculator->getEligibleCallerCount($record);

                        $eligibleCallers = $eligibleCallerCache[$record->id];

                        return $eligibleCallers > 0
                            ? $performanceCache[$record->id]['count_achievement'] / $eligibleCallers
                            : 0;
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('incentive')
                    ->label('Incentive')
                    ->badge()
                    ->color('success')
                    ->alignEnd()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['incentive'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('cashback')
                    ->label('Cashback')
                    ->alignEnd()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['cashback'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('subvention')
                    ->label('Subvention')
                    ->alignEnd()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['subvention'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('docking')
                    ->label('Docking')
                    ->alignEnd()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['docking'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                //
            ])
            ->filters([
                //

                SelectFilter::make('designation')
                    ->multiple()
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
                        fn ($query) => $query->whereIn(
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
                        fn ($query) => $query->whereIn(
                            'id',
                            HierarchyHelper::visibleEmployeeIds(auth()->user())
                        )
                    )
                    ->searchable()
                    ->preload(),

                SelectFilter::make('superviser_id')
                    ->label('Team Leader')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::forDesignation(Employee::DESIGNATION_TEAM_LEADER)),

            ])
            ->recordActions([
                // EditAction::make(),
                // Action::make('View Team')
                // ->icon('heroicon-o-eye')

                Action::make('View Team')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Employee $record) => TeamResource::getUrl('view', [
                        'record' => $record,
                    ])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
