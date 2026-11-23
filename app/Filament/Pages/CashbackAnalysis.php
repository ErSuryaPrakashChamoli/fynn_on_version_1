<?php

namespace App\Filament\Pages;

use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackRequestStatus;
use App\Enums\CashbackType;
use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestActions;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;
use App\Filament\Resources\CustomerCashbackRequests\Schemas\CustomerCashbackRequestInfolist;
use App\Models\CustomerCashbackRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\CashbackAnalysisService;
use App\Services\CustomerCashbackService;
use App\Support\EmployeeLookupPeriod;
use App\Support\EmployeeOptions;
use App\Support\HierarchyHelper;
use App\Support\SelectedMonth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Customers → Cashback Analysis: the cashback given by one employee —
 * Caller, Team Leader, Manager, Cluster Manager or above, each with their
 * branch rolled up — or the whole company, in one month till date
 * (CashbackAnalysisService): cases punched in, cases with cashback and with
 * subvention, total cashback and subvention, disbursal and approval amounts
 * and their % of disbursal, split by the
 * people reporting to them, then every cashback request raised in the month
 * with the analysis saved when it was raised, for future reference.
 *
 * Same audience as Cashback Forms. Admin and Accounts analyse anyone or the
 * whole company; everybody else themselves and their team down the line.
 */
class CashbackAnalysis extends Page implements HasTable
{
    use HasColumnHeaderFilters;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPercentBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Request';

    protected static ?string $navigationLabel = 'Cashback Analysis';

    protected static ?string $title = 'Cashback Analysis';

    protected static ?string $slug = 'cashback-analysis';

    protected string $view = 'filament.pages.cashback-analysis';

    /** The employee analysed; empty is the whole company (Admin / Accounts) or the viewer themselves. */
    #[Url(as: 'employee')]
    public ?string $employeeId = null;

    /** "Y-m"; defaults to the topbar month. */
    #[Url]
    public ?string $month = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    protected ?array $analysisCache = null;

    public static function canAccess(): bool
    {
        return CustomerCashbackRequestResource::canAccess();
    }

    /**
     * Who $user may analyse: null is everyone (Admin and Accounts), else
     * themselves and their team down the line.
     *
     * @return Collection<int, int>|null
     */
    public static function analysableIds(?User $user): ?Collection
    {
        if (! $user instanceof User) {
            return collect();
        }

        if (CustomerCashbackService::isPaymentUser($user)) {
            return null;
        }

        return $user->employee ? HierarchyHelper::visibleSubordinateIds($user->employee) : collect();
    }

    public function mount(): void
    {
        $this->month = $this->selectedMonth()->format('Y-m');
        $employee = $this->selectedEmployee();
        $this->employeeId = $employee ? (string) $employee->id : null;

        $this->form->fill([
            'employee' => $employee?->id,
            'month' => $this->month,
        ]);
    }

    public function getSubheading(): ?string
    {
        return $this->subjectLabel().' · '.$this->selectedMonth()->format('F Y').($this->selectedMonth()->isSameMonth(now()) ? ' till '.now()->format('d M Y') : '');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'sm' => 3])
                    ->schema([
                        Select::make('employee')
                            ->label('Employee')
                            ->placeholder($this->canAnalyseCompany() ? 'Whole company' : 'Search by employee name or ID…')
                            ->options(fn (): array => $this->employeeOptions())
                            ->getSearchResultsUsing(fn (string $search): array => $this->employeeOptions($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => ($employee = $this->findAnalysable($value)) ? EmployeeOptions::label($employee) : null)
                            ->live()
                            ->afterStateUpdated(fn (mixed $state) => $this->analyseEmployee($state))
                            ->columnSpan(['default' => 1, 'sm' => 2]),

                        Select::make('month')
                            ->label('Month')
                            ->options(fn (): array => EmployeeLookupPeriod::monthOptions())
                            ->searchable(false)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->afterStateUpdated(fn (mixed $state) => $this->analyseMonth($state)),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Switches to another employee (the dropdown, or a name in the team
     * split). The id arrives from the browser, so it is checked again; an
     * empty one is the whole company for Admin / Accounts.
     */
    public function analyseEmployee(mixed $employeeId): void
    {
        $employee = $this->findAnalysable($employeeId);

        if ($employee === null && ! (blank($employeeId) && $this->canAnalyseCompany())) {
            return;
        }

        $this->employeeId = $employee ? (string) $employee->id : null;
        $this->data['employee'] = $employee?->id;
        $this->analysisCache = null;
        $this->resetTable();
    }

    public function analyseMonth(mixed $month): void
    {
        if (! array_key_exists((string) $month, EmployeeLookupPeriod::monthOptions())) {
            return;
        }

        $this->month = (string) $month;
        $this->analysisCache = null;
        $this->resetTable();
    }

    /**
     * The employee being analysed, re-checked against the viewer on every
     * request; null is the whole company.
     */
    public function selectedEmployee(): ?Employee
    {
        $employee = $this->findAnalysable($this->employeeId);

        if ($employee !== null || $this->canAnalyseCompany()) {
            return $employee;
        }

        return $this->viewer()?->employee;
    }

    public function selectedMonth(): Carbon
    {
        if (is_string($this->month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1) {
            return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
        }

        return SelectedMonth::selectedMonth()->copy()->startOfMonth();
    }

    /**
     * The figures, the team split and the month's requests, worked out once
     * per request.
     *
     * @return array{figures: array<string, int|float>, breakdown: list<array<string, mixed>>, requests: array<string, int|float>}
     */
    public function analysis(): array
    {
        if ($this->analysisCache !== null) {
            return $this->analysisCache;
        }

        $service = app(CashbackAnalysisService::class);
        $employee = $this->selectedEmployee();
        $month = $this->selectedMonth();
        $figures = $service->figures($employee, $month);

        return $this->analysisCache = [
            'figures' => $figures,
            'breakdown' => $service->breakdown($employee, $month, $figures),
            'requests' => $service->requestSummary($employee, $month),
        ];
    }

    public function subjectLabel(): string
    {
        $employee = $this->selectedEmployee();

        return $employee ? EmployeeOptions::label($employee) : 'Whole company';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(CashbackAnalysisService::class)
                ->requestsQuery($this->selectedEmployee(), $this->selectedMonth())
                ->with(['customer', 'aro', 'requester']))
            ->heading(fn (): string => 'Cashback requests raised in '.$this->selectedMonth()->format('F Y'))
            ->description('Every cashback form raised this month on cases in this branch, each with the analysis saved when it was raised — open "Analysis" to see what the approver had in front of them.')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('voucher_no')
                    ->label('Voucher No')
                    ->weight('bold')
                    ->url(fn (CustomerCashbackRequest $record): string => CustomerCashbackRequestResource::getUrl('view', ['record' => $record]))
                    ->description(fn (CustomerCashbackRequest $record): ?string => $record->isRevision() ? 'Revision '.$record->revision_no.' — second payment' : null)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Raised on')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),

                TextColumn::make('customer.customer_name')
                    ->label('Customer')
                    ->description(fn (CustomerCashbackRequest $record): ?string => $record->customer?->application_no ?: $record->customer?->mobile_no)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('aro.emp_name')
                    ->label('Case owner')
                    ->description(fn (CustomerCashbackRequest $record): ?string => $record->aro?->emp_id)
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('requester.name')
                    ->label('Filled by')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Declaration')
                    ->badge()
                    ->formatStateUsing(fn (CashbackType $state): string => $state->label())
                    ->color(fn (CashbackType $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->state(fn (CustomerCashbackRequest $record): float => $record->effectiveAmount())
                    ->formatStateUsing(fn (float $state): string => '₹'.indianNumberFormat($state))
                    ->description(fn (CustomerCashbackRequest $record): ?string => $record->approved_amount !== null && (float) $record->approved_amount !== (float) $record->amount
                        ? 'Asked ₹'.indianNumberFormat($record->amount)
                        : null)
                    ->alignEnd()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('COALESCE(approved_amount, amount) '.($direction === 'desc' ? 'desc' : 'asc'))),

                TextColumn::make('subvention_amount')
                    ->label('Subvention declared')
                    ->formatStateUsing(fn ($state): string => '₹'.indianNumberFormat($state))
                    ->tooltip('The case\'s subvention declared on this form.')
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('case_cashback_percent')
                    ->label('Case cashback %')
                    ->state(fn (CustomerCashbackRequest $record): ?string => self::snapshotValue($record, 'case.cashback_percent'))
                    ->description(fn (CustomerCashbackRequest $record): ?string => $record->analysis_snapshot
                        ? '₹'.indianNumberFormat(round((float) data_get($record->analysis_snapshot, 'case.cashback_amount'))).' on ₹'.indianNumberFormat(round((float) data_get($record->analysis_snapshot, 'case.disbursal_amount')))
                            .(data_get($record->analysis_snapshot, 'case.subvention_percent') !== null ? ' · subvention '.self::snapshotValue($record, 'case.subvention_percent') : '')
                        : null)
                    ->tooltip('This case\'s cashback (and subvention) against its disbursal when the form was raised.')
                    ->placeholder('Not saved')
                    ->alignEnd(),

                TextColumn::make('owner_cashback_percent')
                    ->label('Owner\'s month at raising')
                    ->state(fn (CustomerCashbackRequest $record): ?string => self::snapshotValue($record, 'owner.cashback_percent'))
                    ->description(fn (CustomerCashbackRequest $record): ?string => ($owner = self::snapshotOwner($record))
                        ? $owner['cases_with_cashback'].' of '.$owner['cases_punched_in'].' cases · ₹'.indianNumberFormat(round((float) $owner['cashback_amount']))
                            .(isset($owner['subvention_percent']) ? ' · subvention '.number_format((float) $owner['subvention_percent'], 2).'%' : '')
                        : null)
                    ->tooltip('The case owner\'s cashback % (and subvention %) of disbursal for the month, when the form was raised.')
                    ->placeholder('Not saved')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label('Approval')
                    ->badge()
                    ->formatStateUsing(fn (CashbackRequestStatus $state): string => $state->label())
                    ->color(fn (CashbackRequestStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn (CashbackPaymentStatus $state): string => $state->label())
                    ->color(fn (CashbackPaymentStatus $state): string => $state->color())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Approval')
                    ->options(CashbackRequestStatus::options()),

                SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options(CashbackPaymentStatus::options()),

                SelectFilter::make('type')
                    ->label('Declaration')
                    ->options(CashbackType::formOptions()),
            ])
            ->recordActions([
                Action::make('analysis')
                    ->label('Analysis')
                    ->icon(Heroicon::OutlinedPercentBadge)
                    ->color('gray')
                    ->modalHeading(fn (CustomerCashbackRequest $record): string => 'Cashback analysis — '.$record->voucher_no)
                    ->modalContent(fn (CustomerCashbackRequest $record): View => view('filament.components.cashback-form-analysis', CustomerCashbackRequestInfolist::analysisViewData($record)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth(Width::SixExtraLarge),
                CustomerCashbackRequestActions::approve(after: fn () => $this->analysisCache = null),
                CustomerCashbackRequestActions::reject(after: fn () => $this->analysisCache = null),
            ])
            ->emptyStateHeading('No cashback requests raised in this month')
            ->emptyStateDescription('Pick another month or employee at the top of the page.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            ...$this->analysis(),
            'subject' => $this->subjectLabel(),
            'monthLabel' => $this->selectedMonth()->format('F Y'),
            'isCurrentMonth' => $this->selectedMonth()->isSameMonth(now()),
            'isCompany' => $this->selectedEmployee() === null,
        ];
    }

    protected function canAnalyseCompany(): bool
    {
        return static::analysableIds($this->viewer()) === null;
    }

    protected function findAnalysable(mixed $employeeId): ?Employee
    {
        if (blank($employeeId) || ! is_numeric($employeeId)) {
            return null;
        }

        $ids = static::analysableIds($this->viewer());

        if ($ids !== null && ! $ids->contains((int) $employeeId)) {
            return null;
        }

        return Employee::query()->find((int) $employeeId);
    }

    /**
     * Whom the viewer may analyse, filtered by name or employee ID — the
     * whole list for a team, the first matches company-wide.
     *
     * @return array<int, string>
     */
    protected function employeeOptions(?string $search = null): array
    {
        $ids = static::analysableIds($this->viewer());
        $search = trim((string) $search);

        if ($ids === null && $search === '') {
            return [];
        }

        return Employee::query()
            ->when($ids !== null, fn (Builder $query): Builder => $query->whereIn('id', $ids))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                ->where('emp_name', 'like', "%{$search}%")
                ->orWhere('emp_id', 'like', "%{$search}%")))
            ->orderBy('emp_name')
            ->limit(50)
            ->get(['id', 'emp_id', 'emp_name'])
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => EmployeeOptions::label($employee)])
            ->all();
    }

    /** A cashback % from the analysis saved on the form ("owner." reads the case owner's row). */
    protected static function snapshotValue(CustomerCashbackRequest $record, string $path): ?string
    {
        $value = str_starts_with($path, 'owner.')
            ? (self::snapshotOwner($record)[substr($path, 6)] ?? null)
            : data_get($record->analysis_snapshot, $path);

        return $value === null ? null : number_format((float) $value, 2).'%';
    }

    /**
     * The case owner's row of the analysis saved on the form.
     *
     * @return array<string, mixed>|null
     */
    protected static function snapshotOwner(CustomerCashbackRequest $record): ?array
    {
        return collect(data_get($record->analysis_snapshot, 'people', []))
            ->first(fn (array $person): bool => str_starts_with((string) ($person['role'] ?? ''), 'Case owner'));
    }

    protected function viewer(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }
}
