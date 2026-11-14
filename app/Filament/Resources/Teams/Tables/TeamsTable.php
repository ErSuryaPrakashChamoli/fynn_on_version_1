<?php

namespace App\Filament\Resources\Teams\Tables;

use App\Filament\Resources\Teams\TeamResource;
use App\Models\Employee;
use App\Models\TargetCategory;
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

        // A non-Admin's list is their own team for the selected month, so
        // each row shows its contribution to that team — only the days the
        // person was in it (see AchievementCalculatorService::getPerformance()).
        // The Admin's rows are the tops of the branches, shown whole.
        $viewer = auth()->user();
        $team = $viewer && ! $viewer->hasRole('Admin') ? $viewer->employee : null;

        return $table
            ->columns([

                TextColumn::make('emp_name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Employee $record): ?string => $team
                        ? $calculator->reportingSpanNote($record, $team)
                        : null),

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
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                        return TargetCategory::labelFor($performanceCache[$record->id]['target_category']);
                    }),

                // TextColumn::make('target_category')
                //     ->label('Category')
                //     ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                //         if ($record->designation !== Employee::DESIGNATION_CALLER) {
                //             return null;
                //         }

                //         $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                //         return $performanceCache[$record->id]['target_category'];
                //     }),

                TextColumn::make('target')
                    ->label('Target')
                    ->alignEnd()
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

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

                            return $team
                                ? $calculator->getTarget($record, null, $team)
                                : $calculator->getHierarchyCallerTarget($record);
                        }

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                        return $performanceCache[$record->id]['target'];
                    })
                    /*
                    |--------------------------------------------------------------------------
                    | Why a partial-month figure looks the way it does
                    |--------------------------------------------------------------------------
                    |
                    | A caller who joined (or left) mid-month carries the flat
                    | partial-month target rather than their category, and while
                    | they are still on the rolls that figure rests on them
                    | staying to month-end. Saying so here is the point of the
                    | 2026-09-10 change: a Team Leader was previously left
                    | guessing whether a 0 meant "no target set" or "genuinely
                    | nil". The wording lives on the service, next to the rule.
                    |
                    */
                    ->tooltip(function (Employee $record) use ($calculator, $team): ?string {

                        // The caller's own row shows their flat category
                        // target, which the notes would not describe.
                        if (auth()->user()?->employee?->id === $record->id) {
                            return null;
                        }

                        $notes = array_filter([
                            $record->designation === Employee::DESIGNATION_CALLER
                                ? $calculator->hierarchyCallerTargetNote($record)
                                : null,
                            $team ? $calculator->reportingSpanNote($record, $team, withRule: true) : null,
                        ]);

                        return $notes === [] ? null : implode(' ', $notes);
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('actual')
                    ->label('Actual')
                    ->alignEnd()
                    ->color('success')
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                        return $performanceCache[$record->id]['actual'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('count_achievement')
                    ->label('Count Achievement')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

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
                    ->state(function (Employee $record) use ($calculator, &$eligibleCallerCache) {

                        if ($record->designation === Employee::DESIGNATION_CALLER) {
                            return null;
                        }

                        $eligibleCallerCache[$record->id] ??= $calculator->getEligibleCallerCount($record);

                        $eligibleCallers = $eligibleCallerCache[$record->id];

                        // PPP is the row's own incentive figure: their whole
                        // branch's month, not just the part counted here.
                        return $eligibleCallers > 0
                            ? $calculator->getCountAchievement($record) / $eligibleCallers
                            : 0;
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('incentive')
                    ->label('Incentive')
                    ->badge()
                    ->color('success')
                    ->alignEnd()
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                        return $performanceCache[$record->id]['incentive'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('cashback')
                    ->label('Cashback')
                    ->alignEnd()
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                        return $performanceCache[$record->id]['cashback'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('subvention')
                    ->label('Subvention')
                    ->alignEnd()
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                        return $performanceCache[$record->id]['subvention'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null),

                TextColumn::make('docking')
                    ->label('Docking')
                    ->alignEnd()
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

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
                        Employee::DESIGNATION_BUSINESS_HEAD => 'Business Head',
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
                    // Was bound to 'superviser', so "Cluster Manager" filtered by Team Leader.
                    ->relationship(
                        'cluster',
                        'emp_name',
                        fn ($query) => $query
                            ->where('designation', Employee::DESIGNATION_CLUSTER)
                            ->whereIn('id', HierarchyHelper::visibleEmployeeIds(auth()->user()))
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
