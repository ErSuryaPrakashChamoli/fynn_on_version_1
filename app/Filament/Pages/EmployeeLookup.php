<?php

namespace App\Filament\Pages;

use App\Enums\EmployeeLookupSection;
use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerStageHistory;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\TargetCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserLoginSession;
use App\Services\Performance\EmployeeFigureBreakdownService;
use App\Services\Performance\EmployeePerformanceMetricsService;
use App\Support\EmployeeLookupAccess;
use App\Support\EmployeeLookupPeriod;
use App\Support\EmployeeOptions;
use App\Support\HierarchyHelper;
use App\Support\ItModuleAccess;
use App\Support\ReportingTree;
use App\Support\SelectedMonth;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Everything about one employee, opened from the topbar "Find employee"
 * search (App\Livewire\EmployeeQuickSearch).
 *
 * The profile opens first; the "Actions" menu then moves to the other
 * views (EmployeeLookupSection): target, achievement and incentive, OTP
 * and funnel figures, the reporting hierarchy, and the lists behind them.
 * Who can be opened is App\Support\EmployeeLookupAccess: the Admin anyone,
 * everybody else themselves and their team down the line.
 *
 * The page analyses whatever period is picked at its top — a month, a
 * financial year, all time or a custom range (EmployeeLookupPeriod),
 * following the topbar selector until one is picked — and every card,
 * pop-up and list follows it. "Active only" (the default) hides people who
 * were not on the rolls on any day of that period; "Include inactive"
 * shows them too. Both travel in the URL, so a view can be shared.
 *
 * Every target / achievement / incentive card opens the "explain" pop-up
 * (EmployeeFigureBreakdownService) showing how that figure was made, and
 * every OTP & Funnel card opens the list of exactly what it counts — the
 * lists use the metric service's own scope (each row counted for the team
 * its owner was in on its date), so a card and its list always agree.
 */
class EmployeeLookup extends Page implements HasTable
{
    use HasColumnHeaderFilters;
    use InteractsWithTable;

    /**
     * The figures the explain pop-up can be opened for, and the view each
     * one is explained with.
     *
     * @var array<string, string>
     */
    public const EXPLAINABLE_FIGURES = [
        'target' => 'target',
        'gross' => 'achievement',
        'net' => 'achievement',
        'cashback' => 'achievement',
        'subvention' => 'achievement',
        'docking' => 'achievement',
        'deductions' => 'achievement',
        'percentage' => 'percentage',
        'remaining' => 'remaining',
        'category' => 'category',
        'incentive' => 'incentive',
    ];

    protected static ?string $slug = 'employee-lookup';

    protected static ?string $title = 'Employee Lookup';

    protected string $view = 'filament.pages.employee-lookup';

    #[Url(as: 'employee')]
    #[Locked]
    public ?string $employeeId = null;

    #[Url]
    #[Locked]
    public ?string $section = null;

    /** Period mode picked on the page; null follows the topbar selector. */
    #[Url]
    #[Locked]
    public ?string $period = null;

    #[Url]
    #[Locked]
    public ?string $month = null;

    #[Url]
    #[Locked]
    public ?string $fy = null;

    #[Url]
    #[Locked]
    public ?string $from = null;

    #[Url]
    #[Locked]
    public ?string $to = null;

    #[Url(as: 'inactive')]
    #[Locked]
    public bool $includeInactive = false;

    #[Locked]
    public ?Employee $employee = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** The Customers module's scope for the viewer, memoised per request. */
    protected ?Builder $visibleCustomers = null;

    protected ?EmployeeLookupPeriod $resolvedPeriod = null;

    /** @var Collection<int, int>|null */
    protected ?Collection $activeIds = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && ! ItModuleAccess::restricts($user)
            && EmployeeLookupAccess::canSearch($user);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->employee = EmployeeLookupAccess::find($this->viewer(), $this->employeeId);
        $this->employeeId = $this->employee ? (string) $this->employee->id : null;
        $this->section = $this->currentSection()->value;

        $period = $this->analysisPeriod();

        $this->form->fill([
            'employee' => $this->employee?->id,
            'period_mode' => $period->mode,
            'period_month' => ($period->month ?? now())->format('Y-m'),
            'period_fy' => $period->financialYear ?? SelectedMonth::financialYearStartFor(now()),
            'period_from' => $period->start->toDateString(),
            'period_to' => $period->end->toDateString(),
            'employees' => $this->includeInactive ? 'all' : 'active',
        ]);
    }

    public function getHeading(): string
    {
        return $this->employee ? EmployeeOptions::label($this->employee) : 'Employee Lookup';
    }

    public function getSubheading(): ?string
    {
        return $this->employee
            ? $this->currentSection()->label().' · '.$this->analysisPeriod()->label
            : 'Search an employee by name or employee ID.';
    }

    public function currentSection(): EmployeeLookupSection
    {
        return EmployeeLookupSection::tryFrom((string) $this->section) ?? EmployeeLookupSection::Profile;
    }

    /**
     * The period being analysed: picked on the page, else the topbar's.
     */
    public function analysisPeriod(): EmployeeLookupPeriod
    {
        return $this->resolvedPeriod ??= EmployeeLookupPeriod::fromInput($this->period, $this->month, $this->fy, $this->from, $this->to);
    }

    /**
     * A link to one view of an employee that keeps the period and the
     * active / inactive choice.
     */
    public function sectionUrl(EmployeeLookupSection $section, ?int $employeeId = null): string
    {
        return static::getUrl([
            'employee' => $employeeId ?? $this->employee?->id,
            'section' => $section->value,
            ...$this->analysisQuery(),
        ]);
    }

    /**
     * The URL parameters of the current analysis choices.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, string>
     */
    public function analysisQuery(array $overrides = []): array
    {
        $query = filled($this->period) ? $this->analysisPeriod()->toQuery() : [];

        if ($this->includeInactive) {
            $query['inactive'] = '1';
        }

        return array_filter([...$query, ...$overrides], fn (mixed $value): bool => $value !== null);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'sm' => 2, 'lg' => 6])
                    ->schema([
                        Select::make('employee')
                            ->label('Employee')
                            ->placeholder('Search by employee name or ID…')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => EmployeeLookupAccess::searchOptions($this->viewer(), $search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => ($employee = EmployeeLookupAccess::find($this->viewer(), $value))
                                ? EmployeeOptions::label($employee)
                                : null)
                            ->live()
                            ->afterStateUpdated(fn (mixed $state) => $this->openEmployee($state))
                            ->columnSpan(['default' => 1, 'sm' => 2, 'lg' => 2]),

                        Select::make('period_mode')
                            ->label('Period')
                            ->options(EmployeeLookupPeriod::modeOptions())
                            ->searchable(false)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->afterStateUpdated(fn () => $this->applyAnalysis()),

                        Select::make('period_month')
                            ->label('Month')
                            ->options(fn (): array => EmployeeLookupPeriod::monthOptions())
                            ->selectablePlaceholder(false)
                            ->live()
                            ->afterStateUpdated(fn () => $this->applyAnalysis())
                            ->visible(fn (Get $get): bool => $get('period_mode') === EmployeeLookupPeriod::MODE_MONTH),

                        Select::make('period_fy')
                            ->label('Financial year')
                            ->options(fn (): array => SelectedMonth::financialYearOptions())
                            ->selectablePlaceholder(false)
                            ->live()
                            ->afterStateUpdated(fn () => $this->applyAnalysis())
                            ->visible(fn (Get $get): bool => $get('period_mode') === EmployeeLookupPeriod::MODE_FINANCIAL_YEAR),

                        DatePicker::make('period_from')
                            ->label('From')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->live()
                            ->afterStateUpdated(fn () => $this->applyAnalysis())
                            ->visible(fn (Get $get): bool => $get('period_mode') === EmployeeLookupPeriod::MODE_CUSTOM),

                        DatePicker::make('period_to')
                            ->label('To')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->live()
                            ->afterStateUpdated(fn () => $this->applyAnalysis())
                            ->visible(fn (Get $get): bool => $get('period_mode') === EmployeeLookupPeriod::MODE_CUSTOM),

                        ToggleButtons::make('employees')
                            ->label('Employees')
                            ->options(['active' => 'Active only', 'all' => 'Include inactive'])
                            ->icons(['active' => Heroicon::OutlinedUserCircle, 'all' => Heroicon::OutlinedUserGroup])
                            ->colors(['active' => 'success', 'all' => 'gray'])
                            ->grouped()
                            ->live()
                            ->afterStateUpdated(fn () => $this->applyAnalysis())
                            ->columnSpan(['default' => 1, 'sm' => 2, 'lg' => 2]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Opens the picked employee's profile, re-checking that the viewer may
     * see them: the dropdown's value arrives from the browser.
     */
    protected function openEmployee(mixed $employeeId): void
    {
        $employee = EmployeeLookupAccess::find($this->viewer(), $employeeId);

        if (! $employee) {
            return;
        }

        $this->redirect($this->sectionUrl(EmployeeLookupSection::Profile, $employee->id));
    }

    /**
     * Reloads the page for the period and employee filter just picked. A
     * custom range waits until both of its dates are set.
     */
    protected function applyAnalysis(): void
    {
        $data = $this->data ?? [];
        $mode = (string) ($data['period_mode'] ?? '');

        $period = match ($mode) {
            EmployeeLookupPeriod::MODE_MONTH => ['period' => $mode, 'month' => $data['period_month'] ?? now()->format('Y-m')],
            EmployeeLookupPeriod::MODE_FINANCIAL_YEAR => ['period' => $mode, 'fy' => (string) ($data['period_fy'] ?? SelectedMonth::financialYearStartFor(now()))],
            EmployeeLookupPeriod::MODE_ALL => ['period' => $mode],
            EmployeeLookupPeriod::MODE_CUSTOM => filled($data['period_from'] ?? null) && filled($data['period_to'] ?? null)
                ? ['period' => $mode, 'from' => Carbon::parse($data['period_from'])->toDateString(), 'to' => Carbon::parse($data['period_to'])->toDateString()]
                : null,
            default => [],
        };

        if ($period === null) {
            return;
        }

        $query = [
            'employee' => $this->employee?->id,
            'section' => $this->currentSection()->value,
            ...$period,
        ];

        if (($data['employees'] ?? 'active') === 'all') {
            $query['inactive'] = '1';
        }

        $this->redirect(static::getUrl(array_filter($query, fn (mixed $value): bool => filled($value))));
    }

    protected function getHeaderActions(): array
    {
        if (! $this->employee) {
            return [];
        }

        $current = $this->currentSection();

        $groups = collect(EmployeeLookupSection::cases())
            ->groupBy(fn (EmployeeLookupSection $section): string => $section->group())
            ->map(fn (Collection $sections): ActionGroup => ActionGroup::make(
                $sections
                    ->map(fn (EmployeeLookupSection $section): Action => Action::make('open'.$section->name)
                        ->label($section->label())
                        ->icon($section->icon())
                        ->color($section === $current ? 'primary' : 'gray')
                        ->url($this->sectionUrl($section)))
                    ->all()
            )->dropdown(false))
            ->values()
            ->all();

        return [
            ActionGroup::make($groups)
                ->label('Actions')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->button()
                ->color('primary')
                ->dropdownPlacement('bottom-end'),
        ];
    }

    /**
     * The "how is this calculated?" pop-up behind every target, achievement
     * and incentive card. Opened from the page with
     * mountAction('explain', { figure: 'target' }).
     */
    public function explainAction(): Action
    {
        return Action::make('explain')
            ->visible(fn (): bool => $this->employee !== null)
            ->modalHeading(fn (array $arguments): string => $this->explainHeading($this->explainFigure($arguments)))
            ->modalDescription(fn (): string => ($this->employee ? EmployeeOptions::label($this->employee).' · ' : '').$this->analysisPeriod()->label)
            ->modalContent(fn (array $arguments): View => view(
                'filament.pages.employee-lookup-explain',
                $this->explanation($this->explainFigure($arguments)),
            ))
            ->modalWidth(Width::SevenExtraLarge)
            ->slideOver()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function explainFigure(array $arguments): string
    {
        $figure = (string) ($arguments['figure'] ?? '');

        return array_key_exists($figure, self::EXPLAINABLE_FIGURES) ? $figure : 'net';
    }

    protected function explainHeading(string $figure): string
    {
        return match ($figure) {
            'target' => 'How the target is calculated',
            'gross' => 'How gross achievement is added up',
            'net' => 'How net achievement is added up',
            'cashback', 'subvention', 'docking', 'deductions' => 'How the deductions are worked out',
            'percentage' => 'How achievement % is calculated',
            'remaining' => 'How remaining to target is calculated',
            'category' => 'Target category',
            'incentive' => 'How the incentive is calculated',
        };
    }

    /**
     * Everything the explain view needs for one figure.
     *
     * @return array<string, mixed>
     */
    public function explanation(string $figure): array
    {
        $employee = $this->employee;
        $breakdowns = $this->breakdowns();
        $period = $this->analysisPeriod();

        $data = [
            'figure' => $figure,
            'view' => self::EXPLAINABLE_FIGURES[$figure] ?? 'achievement',
            'period' => $period,
            'includeInactive' => $this->includeInactive,
            // Drill into one month of a longer period, keeping everything else.
            'monthUrl' => fn (Carbon $month): string => static::getUrl([
                'employee' => $employee->id,
                'section' => EmployeeLookupSection::Achievement->value,
                ...$this->analysisQuery(['period' => EmployeeLookupPeriod::MODE_MONTH, 'month' => $month->format('Y-m'), 'fy' => null, 'from' => null, 'to' => null]),
            ]),
            'employee' => $employee,
            'designation' => Employee::designationOptions()[$employee->designation] ?? '—',
            'summary' => $this->achievement,
        ];

        return match ($data['view']) {
            'target' => [...$data, 'target' => $breakdowns->target($employee, $period)],
            'achievement' => [...$data, 'cases' => $breakdowns->achievement($employee, $period)],
            'incentive' => [...$data, 'incentive' => $breakdowns->incentive($employee, $period)],
            'category' => [...$data, 'categoryLabel' => TargetCategory::labelFor($employee->category), 'categoryAmount' => TargetCategory::targetAmountFor($employee->category)],
            default => $data,
        };
    }

    public function periodLabel(): string
    {
        return $this->analysisPeriod()->label;
    }

    /**
     * @return array<string, string>
     */
    public function getProfileDetailsProperty(): array
    {
        $employee = $this->employee;

        if (! $employee) {
            return [];
        }

        $tree = ReportingTree::load();
        $login = $employee->user;
        $lastLogin = UserLoginSession::query()->where('employee_id', $employee->id)->max('login_at');

        return [
            'Employee ID' => (string) ($employee->emp_id ?: '—'),
            'Name' => (string) $employee->emp_name,
            'Designation' => Employee::designationOptions()[$employee->designation] ?? '—',
            'Position' => (string) ($employee->position ?: '—'),
            'Email' => (string) ($employee->email ?: '—'),
            'Status' => EmployeeLookupAccess::isExited($employee)
                ? trim('Exited '.$this->formatDate($employee->exit_date, prefix: 'on '))
                : 'Active',
            'Date of Joining' => $this->formatDate($employee->doj) ?: '—',
            'Reporting Since' => $this->formatDate($employee->reporting_date) ?: '—',
            'Reports To' => EmployeeOptions::reportingBossLabel($employee, $tree) ?? 'Not assigned',
            'Direct Reports' => number_format(count($tree->childIds($employee->id))),
            'Team Down the Line' => number_format(count($tree->descendantIds($employee->id))),
            'Target Category' => TargetCategory::labelFor($employee->category) ?? '—',
            'Cost Center' => CostCenter::labelFor($employee->cost_center) ?? '—',
            'Unit' => Unit::labelFor($employee->unit_name) ?? '—',
            'Login Account' => $login ? (string) $login->email : 'No login account',
            'Roles' => $login ? ($login->getRoleNames()->join(', ') ?: '—') : '—',
            'Last Login' => $lastLogin ? Carbon::parse($lastLogin)->format('d M Y, h:i A') : 'Never',
        ];
    }

    /**
     * Target, achievement, deductions and incentive for the period — the
     * same engine as the dashboard's incentive cards, added up month by
     * month for a longer period.
     *
     * @return array<string, mixed>
     */
    public function getAchievementProperty(): array
    {
        if (! $this->employee) {
            return [];
        }

        return $this->breakdowns()->summary($this->employee, $this->analysisPeriod());
    }

    /**
     * OTP and funnel figures for the period, scoped exactly like the
     * Employee Performance dashboard (their counted branch, rolled up).
     *
     * @return array<string, mixed>
     */
    public function getPerformanceProperty(): array
    {
        if (! $this->employee) {
            return [];
        }

        $period = $this->analysisPeriod();

        $metrics = app(EmployeePerformanceMetricsService::class)->rawMetrics($this->employee, $period->start->copy(), $period->end->copy());

        $metrics['not_eligible_otp_count'] = $this->countedScope(Customer::query(), 'customers.employee_id', 'customers.created_at')
            ->where('eligibility_status', 'not_eligible')
            ->count();

        return $metrics;
    }

    /**
     * Bosses above (top first), direct reports, and the down-line counted
     * by designation. A boss is linked only when the viewer may open them;
     * everyone carries whether they were on the rolls during the period.
     *
     * @return array{chain: list<array<string, mixed>>, reports: list<array<string, mixed>>, levels: list<array{label: string, active: int, exited: int}>}
     */
    public function getHierarchyProperty(): array
    {
        if (! $this->employee) {
            return ['chain' => [], 'reports' => [], 'levels' => []];
        }

        $tree = ReportingTree::load();
        $period = $this->analysisPeriod();
        $visibleIds = EmployeeLookupAccess::visibleIds($this->viewer());
        $designations = Employee::designationOptions();

        $people = Employee::query()
            ->whereIn('id', [...$tree->ancestorIds($this->employee->id), ...$tree->childIds($this->employee->id)])
            ->get(['id', 'emp_id', 'reporting_date', 'doj', 'exit_status', 'exit_date'])
            ->keyBy('id');

        $person = fn (int $id): array => [
            'id' => $id,
            'name' => (string) $tree->name($id),
            'emp_id' => $people->get($id)?->emp_id,
            'designation' => $designations[$tree->designation($id)] ?? '—',
            'exited' => $tree->isExited($id),
            'active' => $people->has($id) && $period->wasActive($people->get($id)),
            'team' => count($tree->descendantIds($id)),
            'url' => $visibleIds === null || $visibleIds->contains($id)
                ? $this->sectionUrl(EmployeeLookupSection::Profile, $id)
                : null,
        ];

        $levels = collect($tree->descendantIds($this->employee->id))
            ->groupBy(fn (int $id): string => $designations[$tree->designation($id)] ?? 'Other')
            ->map(fn (Collection $ids, string $label): array => [
                'label' => $label,
                'active' => $ids->reject(fn (int $id): bool => $tree->isExited($id))->count(),
                'exited' => $ids->filter(fn (int $id): bool => $tree->isExited($id))->count(),
            ])
            ->values()
            ->all();

        return [
            'chain' => array_map($person, array_reverse($tree->ancestorIds($this->employee->id))),
            'reports' => collect($tree->childIds($this->employee->id))
                ->map($person)
                ->sortBy([['active', 'desc'], ['name', 'asc']])
                ->values()
                ->all(),
            'levels' => $levels,
        ];
    }

    public function table(Table $table): Table
    {
        $section = $this->currentSection();

        if (! $this->employee || ! $section->isList()) {
            return $table
                ->query(Customer::query()->whereRaw('1 = 0'))
                ->columns([]);
        }

        $table = match (true) {
            $section === EmployeeLookupSection::Leads => $this->leadsTable($table),
            $section === EmployeeLookupSection::AssignedLeads => $this->assignedLeadsTable($table),
            $section === EmployeeLookupSection::FollowUps => $this->followUpsTable($table),
            $section === EmployeeLookupSection::Logins => $this->loginsTable($table),
            $section->stageMetricKey() !== null => $this->stageTable($table, $section),
            default => $this->customersTable($table, $section),
        };

        return $table
            ->heading($section->label())
            ->description($this->listDescription($section))
            ->filters($this->ownerFilter($section))
            ->emptyStateHeading('Nothing in this period')
            ->emptyStateDescription('Pick a wider period, or include inactive employees, at the top of the page.');
    }

    protected function listDescription(EmployeeLookupSection $section): string
    {
        $parts = ['Period: '.$this->periodLabel().'.'];

        if ($section->isCountedList()) {
            $parts[] = 'The records the OTP & Funnel card counts: each counted for the team its owner was in on its date.';
        }

        $parts[] = $this->includeInactive
            ? 'Including employees who were not active in this period.'
            : 'Active employees only — choose "Include inactive" at the top to see everyone.';

        return implode(' ', $parts);
    }

    protected function customersTable(Table $table, EmployeeLookupSection $section): Table
    {
        return $table
            ->query(fn (): Builder => $this->activeOnly($this->countedScope(
                Customer::query()->with('employee:id,emp_name,emp_id'),
                'customers.employee_id',
                'customers.created_at',
            ), 'customers.employee_id')
                ->when($section === EmployeeLookupSection::EligibleOtp, fn (Builder $query) => $query->where('eligibility_status', 'eligible'))
                ->when($section === EmployeeLookupSection::NotEligibleOtp, fn (Builder $query) => $query->where('eligibility_status', 'not_eligible')))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Customer $record): ?string => $this->customerUrl($record->getKey()))
            ->columns([
                TextColumn::make('application_no')
                    ->label('Application No')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('mobile_no')
                    ->label('Mobile')
                    ->placeholder('-')
                    ->searchable(),
                $this->ownerColumn(),
                TextColumn::make('eligibility_status')
                    ->label('Eligibility')
                    ->badge()
                    ->placeholder('-')
                    ->formatStateUsing(fn (?string $state): string => $this->humanise($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'eligible' => 'success',
                        'not_eligible' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('journey_status')
                    ->label('Journey')
                    ->badge()
                    ->placeholder('-')
                    ->formatStateUsing(fn (?string $state): string => $this->humanise($state)),
                TextColumn::make('sanctioned_loan_amount')
                    ->label('Sanctioned')
                    ->placeholder('-')
                    ->formatStateUsing(fn (mixed $state): string => indianCurrencyFormat($state))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),
            ]);
    }

    /**
     * The stage moves behind a funnel card (logins, approvals, disbursals,
     * dropped, not approved): credited to whoever made the move, on the
     * day they made it — the same rows EmployeePerformanceMetricsService
     * counts.
     */
    protected function stageTable(Table $table, EmployeeLookupSection $section): Table
    {
        $isDisbursals = $section === EmployeeLookupSection::Disbursals;

        return $table
            ->query(fn (): Builder => $this->activeOnly($this->countedScope(
                CustomerStageHistory::query()
                    ->join('users', 'users.id', '=', 'customer_stage_histories.user_id')
                    ->leftJoin('customers', 'customers.id', '=', 'customer_stage_histories.customer_id')
                    ->leftJoin('employees as movers', 'movers.id', '=', 'users.employee_id')
                    ->where('customer_stage_histories.status_value', $this->stageStatus($section->stageMetricKey()))
                    ->select([
                        'customer_stage_histories.*',
                        'customers.application_no as application_no',
                        'customers.customer_name as customer_name',
                        'customers.mobile_no as mobile_no',
                        'customers.sanctioned_bank as sanctioned_bank',
                        'movers.emp_name as mover_name',
                        'movers.emp_id as mover_emp_id',
                    ])
                    ->selectRaw('COALESCE((SELECT cs.mis_disbursal_amount FROM customer_settlements cs WHERE cs.customer_id = customer_stage_histories.customer_id AND cs.version = 1 LIMIT 1), customers.sanctioned_loan_amount) as disbursal_amount'),
                'users.employee_id',
                'customer_stage_histories.created_at',
            ), 'users.employee_id'))
            ->defaultSort('customer_stage_histories.created_at', 'desc')
            ->recordUrl(fn (CustomerStageHistory $record): ?string => $this->customerUrl($record->customer_id))
            ->columns([
                TextColumn::make('application_no')
                    ->label('Application No')
                    ->placeholder('-')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('customers.application_no', 'like', "%{$search}%")),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->placeholder('-')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('customers.customer_name', 'like', "%{$search}%")),
                TextColumn::make('mobile_no')
                    ->label('Mobile')
                    ->placeholder('-'),
                TextColumn::make('mover_name')
                    ->label('Moved By')
                    ->placeholder('-')
                    ->description(fn (CustomerStageHistory $record): ?string => $record->mover_emp_id),
                TextColumn::make('sanctioned_bank')
                    ->label('Bank')
                    ->placeholder('-')
                    ->visible($isDisbursals),
                TextColumn::make('disbursal_amount')
                    ->label('Disbursal Amount')
                    ->placeholder('-')
                    ->formatStateUsing(fn (mixed $state): string => indianCurrencyFormat($state))
                    ->visible($isDisbursals),
                TextColumn::make('created_at')
                    ->label('Moved On')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('customer_stage_histories.created_at', $direction)),
            ]);
    }

    protected function leadsTable(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->activeOnly(Lead::query()
                ->with('employee:id,emp_name,emp_id')
                ->whereIn('leads.employee_id', $this->branchIds())
                ->whereBetween('leads.created_at', $this->periodRange()), 'leads.employee_id'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer_name')
                    ->label('Lead')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('mobile_no')
                    ->label('Mobile')
                    ->placeholder('-')
                    ->searchable(),
                $this->ownerColumn(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->placeholder('Pending'),
                TextColumn::make('next_follow_up_date')
                    ->label('Next Follow-up')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),
            ]);
    }

    protected function assignedLeadsTable(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->activeOnly(CustomerAssignment::query()
                ->with(['employee:id,emp_name,emp_id', 'customer', 'aiCustomerRecord'])
                ->whereIn('customer_assignments.employee_id', $this->branchIds())
                ->whereBetween('customer_assignments.created_at', $this->periodRange()), 'customer_assignments.employee_id'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('display_name')
                    ->label('Prospect'),
                $this->ownerColumn(),
                TextColumn::make('status')
                    ->label('Opened?')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Opened' ? 'success' : 'gray'),
                TextColumn::make('follow_up_status')
                    ->label('Follow-up Status')
                    ->badge()
                    ->state(fn (CustomerAssignment $record): string => $record->latestFollowUpStatus() ?? 'Pending'),
                TextColumn::make('customer.journey_status')
                    ->label('Journey')
                    ->badge()
                    ->placeholder('Not converted')
                    ->formatStateUsing(fn (?string $state): string => $this->humanise($state)),
                TextColumn::make('created_at')
                    ->label('Assigned On')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),
            ]);
    }

    protected function followUpsTable(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->activeOnly(FollowUp::query()
                ->latestPerSubject()
                ->with(['employee:id,emp_name,emp_id', 'customer', 'aiCustomerRecord', 'lead'])
                ->whereIn('follow_ups.employee_id', $this->branchIds())
                ->whereBetween('follow_ups.created_at', $this->periodRange()), 'follow_ups.employee_id'))
            ->defaultSort('next_follow_up_date', 'asc')
            ->columns([
                TextColumn::make('display_name')
                    ->label('Prospect'),
                TextColumn::make('follow_up_type')
                    ->label('Type')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('next_follow_up_date')
                    ->label('Next Follow-up')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('-')
                    ->color(fn (FollowUp $record): ?string => $record->next_follow_up_date?->isPast() ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('remarks')
                    ->label('Remarks')
                    ->limit(60)
                    ->placeholder('-'),
                $this->ownerColumn(),
            ]);
    }

    protected function loginsTable(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->activeOnly($this->countedScope(
                UserLoginSession::query()->with('employee:id,emp_name,emp_id'),
                'user_login_sessions.employee_id',
                'user_login_sessions.login_at',
            ), 'user_login_sessions.employee_id'))
            ->defaultSort('login_at', 'desc')
            ->columns([
                $this->ownerColumn('Employee'),
                TextColumn::make('login_at')
                    ->label('Login')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),
                TextColumn::make('logout_at')
                    ->label('Logout')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('Still signed in'),
                TextColumn::make('screen_time_seconds')
                    ->label('Screen Time')
                    ->formatStateUsing(fn (mixed $state): string => sprintf('%dh %02dm', intdiv((int) $state, 3600), intdiv((int) $state % 3600, 60)))
                    ->sortable(),
                TextColumn::make('logout_reason')
                    ->label('Logout Reason')
                    ->placeholder('-')
                    ->formatStateUsing(fn (?string $state): string => $this->humanise($state)),
            ]);
    }

    protected function ownerColumn(string $label = 'Owner'): TextColumn
    {
        return TextColumn::make('employee.emp_name')
            ->label($label)
            ->placeholder('-')
            ->description(fn (mixed $record): ?string => $record->employee?->emp_id);
    }

    /**
     * Narrows a list to one person of the team — only offered when the
     * employee has a team, so a caller's list carries no pointless filter.
     *
     * @return array<int, SelectFilter>
     */
    protected function ownerFilter(EmployeeLookupSection $section): array
    {
        $teamIds = $section->isCountedList() ? $this->countedTeamIds() : $this->branchIds();

        if (! $this->includeInactive) {
            $teamIds = $teamIds->intersect($this->activeIds())->values();
        }

        if ($teamIds->count() < 2) {
            return [];
        }

        $column = $section->stageMetricKey() !== null ? 'users.employee_id' : null;

        return [
            SelectFilter::make('owner')
                ->label('Team member')
                ->options(fn (): array => Employee::query()
                    ->whereIn('id', $teamIds)
                    ->orderBy('emp_name')
                    ->get(['id', 'emp_name', 'emp_id'])
                    ->mapWithKeys(fn (Employee $employee): array => [$employee->id => EmployeeOptions::label($employee)])
                    ->all())
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    filled($data['value'] ?? null),
                    fn (Builder $query) => $query->where($column ?? $query->qualifyColumn('employee_id'), $data['value']),
                )),
        ];
    }

    /**
     * Rows owned by the employee's team and dated inside the period, scoped
     * exactly as EmployeePerformanceMetricsService::rawMetrics() scopes its
     * counts: the whole company for an Admin record, otherwise each row
     * checked against the team its owner was in on its date.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    protected function countedScope(Builder $query, string $employeeColumn, string $dateColumn): Builder
    {
        [$start, $end] = $this->periodRange();

        if ($this->employee->designation === Employee::DESIGNATION_ADMIN) {
            return $query
                ->whereIn($employeeColumn, Employee::query()->pluck('id'))
                ->whereBetween($dateColumn, [$start, $end]);
        }

        HierarchyHelper::scopeToBranchDuring($query, $this->employee, $start, $end, $employeeColumn, $dateColumn);

        return $query;
    }

    /**
     * Unless inactive employees are asked for, keep only rows owned by
     * somebody on the rolls on at least one day of the period.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    protected function activeOnly(Builder $query, string $employeeColumn): Builder
    {
        return $this->includeInactive ? $query : $query->whereIn($employeeColumn, $this->activeIds());
    }

    /**
     * @return Collection<int, int>
     */
    protected function activeIds(): Collection
    {
        return $this->activeIds ??= $this->analysisPeriod()->activeEmployeeIds();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodRange(): array
    {
        return [$this->analysisPeriod()->start->copy(), $this->analysisPeriod()->end->copy()];
    }

    /**
     * Everybody whose rows the counted lists can hold.
     *
     * @return Collection<int, int>
     */
    protected function countedTeamIds(): Collection
    {
        if ($this->employee->designation === Employee::DESIGNATION_ADMIN) {
            return Employee::query()->pluck('id');
        }

        [$start, $end] = $this->periodRange();

        return HierarchyHelper::subordinateIdsDuring($this->employee, $start, $end);
    }

    /**
     * The customer_stage_histories.status_value a funnel metric counts —
     * the same "Moved to …" wording EmployeePerformanceMetricsService reads.
     */
    protected function stageStatus(?string $metricKey): string
    {
        $journeyStatus = (string) config("performance.stages.{$metricKey}");

        return 'Moved to '.ucfirst(str_replace('_', ' ', $journeyStatus));
    }

    /**
     * The employee and everyone below them, through exited levels too: the
     * same "who can be seen" walk the Customers listing uses.
     *
     * @return Collection<int, int>
     */
    protected function branchIds(): Collection
    {
        return $this->employee
            ? HierarchyHelper::visibleSubordinateIds($this->employee)
            : collect();
    }

    /**
     * The customer's page, when the viewer can open it from the Customers
     * module; otherwise the row is not a link.
     */
    protected function customerUrl(mixed $customerId): ?string
    {
        if (blank($customerId)) {
            return null;
        }

        // Built once per request: the resource's scope is several queries.
        $this->visibleCustomers ??= CustomerResource::getEloquentQuery();

        $visible = (clone $this->visibleCustomers)->whereKey($customerId)->exists();

        return $visible ? CustomerResource::getUrl('view', ['record' => $customerId]) : null;
    }

    protected function breakdowns(): EmployeeFigureBreakdownService
    {
        return app(EmployeeFigureBreakdownService::class);
    }

    protected function viewer(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected function humanise(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }

    protected function formatDate(mixed $value, string $prefix = ''): string
    {
        if (blank($value)) {
            return '';
        }

        try {
            return $prefix.Carbon::parse($value)->format('d M Y');
        } catch (Throwable) {
            return $prefix.$value;
        }
    }
}
