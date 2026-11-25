<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\Teams\TeamResource;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use App\Support\HierarchyHelper;
use App\Support\SelectedMonth;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ViewTeam extends Page implements HasTable
{
    use HasColumnHeaderFilters;
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

        $month = SelectedMonth::current();

        // Everyone who reported straight to this boss on any day of the
        // selected month, and each row's figures as their contribution to
        // this team: someone who moved in or out mid-month shows only the
        // days they were here, so the rows add up to the team's total.
        $team = $this->record;

        return $table
            ->query(HierarchyHelper::childrenDuring(
                $team,
                $month->copy()->startOfMonth(),
                $month->copy()->endOfMonth(),
            ))
            ->columns([

                Tables\Columns\TextColumn::make('emp_name')
                    ->label('Employee')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Employee $record): ?string => $calculator->reportingSpanNote($record, $team)),

                Tables\Columns\TextColumn::make('emp_id')
                    ->label('Emp ID')
                    ->searchable()
                    ->sortable(),

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
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

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

                            return $calculator->getTarget($record, null, $team);
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Team Leader / Manager / Cluster rows
                        |--------------------------------------------------------------------------
                        |
                        | Their own target is already calculated correctly.
                        |
                        */

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                        return $performanceCache[$record->id]['target'];
                    })
                    /*
                    |--------------------------------------------------------------------------
                    | Why a partial-month figure looks the way it does
                    |--------------------------------------------------------------------------
                    |
                    | A caller who joined (or left) mid-month brings their full
                    | category target only with 10+ days on the rolls, and while
                    | they are still on the rolls that rests on them staying to
                    | month-end; a mover's target is split by days between teams.
                    | Saying so here is the point of the 2026-09-10 change: a Team
                    | Leader was previously left guessing whether a 0 meant "no
                    | target set" or "genuinely nil". The wording lives on the
                    | service, next to the rule (TargetLedger).
                    |
                    */
                    ->tooltip(function (Employee $record) use ($calculator, $team): ?string {

                        // The caller's own row shows their full category
                        // target, which the notes would not describe.
                        if (auth()->user()?->employee?->id === $record->id) {
                            return null;
                        }

                        $notes = array_filter([
                            $record->designation === Employee::DESIGNATION_CALLER
                                ? $calculator->hierarchyCallerTargetNote($record)
                                : null,
                            $calculator->reportingSpanNote($record, $team, withRule: true),
                        ]);

                        return $notes === [] ? null : implode(' ', $notes);
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('actual')
                    ->label('Actual')
                    ->alignEnd()
                    ->sortable()
                    ->color('success')
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                        return $performanceCache[$record->id]['actual'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('cashback')
                    ->label('Cashback')
                    ->alignEnd()
                    ->toggleable()
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                        return $performanceCache[$record->id]['cashback'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('subvention')
                    ->label('Subvention')
                    ->alignEnd()
                    ->toggleable()
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                        return $performanceCache[$record->id]['subvention'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('docking')
                    ->label('Docking')
                    ->alignEnd()
                    ->toggleable()
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                        return $performanceCache[$record->id]['docking'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('count_achievement')
                    ->label('Count Achievement')
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable()
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

                        return $performanceCache[$record->id]['count_achievement'];
                    })
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('percentage')
                    ->label('Achievement')
                    ->alignCenter()
                    ->badge()
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

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
                    ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : '-'),

                Tables\Columns\TextColumn::make('incentive')
                    ->label('Incentive')
                    ->alignEnd()
                    ->weight('bold')
                    ->badge()
                    ->color('success')
                    ->sortable()
                    ->state(function (Employee $record) use ($calculator, $team, &$performanceCache) {

                        $performanceCache[$record->id] ??= $calculator->getPerformance($record, null, $team);

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
