<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use Filament\Resources\Pages\Page;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use App\Support\HierarchyHelper;

use Filament\Tables\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builde;

use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Actions\Action;
use App\Services\AchievementCalculatorService;




class ViewTeam extends Page implements HasTable
{
    use InteractsWithTable;

    public Collection $children;

    protected static string $resource = TeamResource::class;

    protected string $view = 'filament.resources.teams.pages.view-team';


    public Employee $record;

    public array $performance = [];

    public function mount(Employee $record): void
    {
        $this->record = $record;

        $calculator = app(\App\Services\AchievementCalculatorService::class);

        $this->performance = $calculator->getPerformance($record);
    }

    public function table(Table $table): Table
    {
        $calculator = app(AchievementCalculatorService::class);
        $performanceCache = [];

        return $table
            // ->query(
            //     HierarchyHelper::children($this->record)
            // )
            ->query(HierarchyHelper::children($this->record))
            ->columns([

                Tables\Columns\TextColumn::make('emp_name')
                    ->label('Employee')
                    ->searchable(),


                Tables\Columns\TextColumn::make('designation')
                    ->label('Position')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(
                        fn($state) => Employee::designationOptions()[$state] ?? 'Unknown'
                    )
                    ->color(fn($state) => match ((int) $state) {
                        Employee::DESIGNATION_CLUSTER => 'primary',
                        Employee::DESIGNATION_MANAGER => 'success',
                        Employee::DESIGNATION_TEAM_LEADER => 'warning',
                        Employee::DESIGNATION_CALLER => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('target')
                    ->label('Target')
                    ->alignEnd()
                    ->sortable()
                    // ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                    //     $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                    //     return $performanceCache[$record->id]['target'];
                    // })
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {


                        /*
                        |--------------------------------------------------------------------------
                        | Caller
                        |--------------------------------------------------------------------------
                        |
                        | If the logged-in viewer is the Caller himself,
                        | he sees his category target.
                        |
                        */

                        if (
                            auth()->user()?->employee?->id === $record->id
                            && $record->designation === Employee::DESIGNATION_CALLER
                        ) {
                            return $calculator->getTarget($record);
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Team Leader / Manager / Cluster viewing Caller
                        |--------------------------------------------------------------------------
                        |
                        | Above hierarchy must see the caller's hierarchy target,
                        | based on reporting_date conditions.
                        |
                        */

                        // if ($record->designation === Employee::DESIGNATION_CALLER) {

                        //     return $calculator->getHierarchyCallerTarget($record);
                        // }

                        if ($record->designation === Employee::DESIGNATION_CALLER) {

                            // Inactive caller who exited in current month
                            if (
                                strtolower((string) $record->exit_status) === 'yes'
                                && filled($record->exit_date)
                            ) {
                                $exitDate = \Carbon\Carbon::parse($record->exit_date);
                                $today = \Carbon\Carbon::today();

                                if (
                                    $exitDate->year === $today->year
                                    && $exitDate->month === $today->month
                                ) {
                                    return $exitDate->day >= 10
                                        ? 1500000
                                        : 0;
                                }

                                // Employee exited before current month
                                if ($exitDate->lt($today->copy()->startOfMonth())) {
                                    return 0;
                                }
                            }

                            return $calculator->getHierarchyCallerTarget($record);
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Team Leader / Manager / Cluster rows
                        |--------------------------------------------------------------------------
                        |
                        | Their own target is already calculated correctly.
                        |
                        */

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['target'];
                    })
                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : '-'),



                Tables\Columns\TextColumn::make('actual')
                    ->label('Actual')
                    ->alignEnd()
                    ->sortable()
                    ->color('success')
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['actual'];
                    })
                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : '-'),


                Tables\Columns\TextColumn::make('cashback')
                    ->label('Cashback')
                    ->alignEnd()
                    ->toggleable()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['cashback'];
                    })
                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('subvention')
                    ->label('Subvention')
                    ->alignEnd()
                    ->toggleable()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['subvention'];
                    })
                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('docking')
                    ->label('Docking')
                    ->alignEnd()
                    ->toggleable()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['docking'];
                    })
                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('count_achievement')
                    ->label('Count Achievement')
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['count_achievement'];
                    })
                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('percentage')
                    ->label('Achievement')
                    ->alignCenter()
                    ->badge()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return round($performanceCache[$record->id]['percentage'], 2);
                    })
                    ->suffix('%')
                    ->color(fn($state) => $state >= 100 ? 'success' : ($state >= 80 ? 'warning' : 'danger')),


                Tables\Columns\TextColumn::make('incentive')
                    ->label('Incentive')
                    ->alignEnd()
                    ->weight('bold')
                    ->badge()
                    ->color('success')
                    ->sortable()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['incentive'];
                    })
                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('mobile'),

                Tables\Columns\TextColumn::make('email'),
            ])
            ->recordActions([

                Action::make('viewTeam')
                    ->label('View Team')
                    ->icon('heroicon-o-users')
                    ->visible(fn(Employee $record) => $record->designation !== Employee::DESIGNATION_CALLER)
                    ->url(fn(Employee $record) => TeamResource::getUrl('view-team', [
                        'record' => $record,
                    ])),

                Action::make('viewCustomers')
                    ->label('Customers')
                    ->icon('heroicon-o-user-group')
                    ->color('success')
                    ->url(fn(Employee $record) => TeamResource::getUrl('view-customers', [
                        'record' => $record,
                    ])),
            ]);
    }


    public function getBreadcrumbs(): array
    {
        $breadcrumbs = [
            TeamResource::getUrl() => 'Teams',
        ];

        foreach (HierarchyHelper::breadcrumb($this->record) as $item) {
            $breadcrumbs[$item['url']] = $item['label'];
        }

        return $breadcrumbs;
    }

    public function getTitle(): string
    {
        return "{$this->record->emp_name} - Team";
    }
}
