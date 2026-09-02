<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use App\Support\HierarchyHelper;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

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

        $calculator = app(AchievementCalculatorService::class);

        $this->performance = $calculator->getPerformance($record);
    }

    public function table(Table $table): Table
    {
        $calculator = app(AchievementCalculatorService::class);
        $performanceCache = [];
        $eligibleCallerCache = [];

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
                        fn ($state) => Employee::designationOptions()[$state] ?? 'Unknown'
                    )
                    ->color(fn ($state) => match ((int) $state) {
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
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        /*
                        |--------------------------------------------------------------------------
                        | Caller
                        |--------------------------------------------------------------------------
                        |
                        | The Caller himself sees his flat category target. Anyone
                        | above him in the hierarchy sees the entry/exit-adjusted
                        | target instead, via the canonical engine — which already
                        | applies the exit/new-joiner rules and respects the global
                        | month selector (a hand-rolled date check here previously
                        | compared against real "today" instead, silently producing
                        | wrong results whenever a past month was selected).
                        |
                        */

                        if ($record->designation === Employee::DESIGNATION_CALLER) {

                            if (auth()->user()?->employee?->id === $record->id) {
                                return $calculator->getTarget($record);
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
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('actual')
                    ->label('Actual')
                    ->alignEnd()
                    ->sortable()
                    ->color('success')
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['actual'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('cashback')
                    ->label('Cashback')
                    ->alignEnd()
                    ->toggleable()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['cashback'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('subvention')
                    ->label('Subvention')
                    ->alignEnd()
                    ->toggleable()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['subvention'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('docking')
                    ->label('Docking')
                    ->alignEnd()
                    ->toggleable()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['docking'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('count_achievement')
                    ->label('Count Achievement')
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return $performanceCache[$record->id]['count_achievement'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('percentage')
                    ->label('Achievement')
                    ->alignCenter()
                    ->badge()
                    ->state(function (Employee $record) use ($calculator, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record);

                        return round($performanceCache[$record->id]['percentage'], 2);
                    })
                    ->suffix('%')
                    ->color(fn ($state) => $state >= 100 ? 'success' : ($state >= 80 ? 'warning' : 'danger')),

                Tables\Columns\TextColumn::make('eligible_callers')
                    ->label('Eligible Callers')
                    ->alignCenter()
                    ->toggleable()
                    ->state(function (Employee $record) use ($calculator, &$eligibleCallerCache) {

                        if ($record->designation === Employee::DESIGNATION_CALLER) {
                            return null;
                        }

                        $eligibleCallerCache[$record->id] ??= $calculator->getEligibleCallerCount($record);

                        return $eligibleCallerCache[$record->id];
                    })
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('ppp')
                    ->label('PPP')
                    ->alignEnd()
                    ->toggleable()
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
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

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
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('mobile'),

                Tables\Columns\TextColumn::make('email'),
            ])
            ->recordActions([

                Action::make('viewTeam')
                    ->label('View Team')
                    ->icon('heroicon-o-users')
                    ->visible(fn (Employee $record) => $record->designation !== Employee::DESIGNATION_CALLER)
                    ->url(fn (Employee $record) => TeamResource::getUrl('view-team', [
                        'record' => $record,
                    ])),

                Action::make('viewCustomers')
                    ->label('Customers')
                    ->icon('heroicon-o-user-group')
                    ->color('success')
                    ->url(fn (Employee $record) => TeamResource::getUrl('view-customers', [
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
