<?php

namespace App\Filament\Pages;

use App\Enums\FollowUpOutcome;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Services\FollowUpMonitorService;
use App\Support\DueDateInput;
use App\Support\EmployeeOptions;
use App\Support\FollowUpCalendarCache;
use App\Support\FollowUpRecordUrl;
use App\Support\SelectedMonth;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * A listing of the follow-ups that fell (or fall) due in a date range,
 * tabbed by what became of them — On time / Late / Missed / Open, the same
 * verdicts as the calendars' day chips (FollowUpMonitorService::classify()).
 *
 * The Lead and Customer pages differ only in which follow-ups they may see,
 * matching the Dashboard calendar's Lead / Customer tabs. The verdicts are
 * worked out once per range and cached like the calendar's day chips; the
 * table then lists the active tab's follow-ups by id.
 */
abstract class FollowUpOutcomeListPage extends Page implements HasTable
{
    use HasTabs;
    use InteractsWithTable;

    #[Url]
    public ?string $activeTab = null;

    /**
     * Due from / due to (Y-m-d) and the owner, kept in the URL so the
     * calendar's outcome list can link straight to a tab and range.
     *
     * @var array{from?: ?string, to?: ?string, employee_id?: int|string|null}|null
     */
    #[Url]
    public ?array $filters = [];

    /**
     * The verdicts for the current filters, worked out at most once per
     * request.
     *
     * @var array{groups: array<string, list<int>>, rows: array<int, array{outcome: string, acted_at: ?string}>}|null
     */
    protected ?array $classification = null;

    /**
     * The follow-ups this page may list, before any date or verdict.
     *
     * @return Builder<FollowUp>
     */
    abstract protected function visibleFollowUpQuery(): Builder;

    /**
     * The kinds of record this page lists, for the Record filter.
     *
     * @return array<string, string>
     */
    abstract protected function recordSourceOptions(): array;

    public function mount(): void
    {
        [$defaultFrom, $defaultTo] = $this->defaultPeriod();

        $employeeId = $this->filters['employee_id'] ?? null;

        $this->form->fill([
            'from' => (DueDateInput::parse($this->filters['from'] ?? null) ?? $defaultFrom)->toDateString(),
            'to' => (DueDateInput::parse($this->filters['to'] ?? null) ?? $defaultTo)->toDateString(),
            'employee_id' => filled($employeeId) && array_key_exists((int) $employeeId, EmployeeOptions::visibleTo()) ? (int) $employeeId : null,
        ]);

        if (! array_key_exists($this->activeTab ?? '', FollowUpOutcome::legendGroups())) {
            $this->activeTab = $this->getDefaultActiveTab();
        }
    }

    public function getSubheading(): ?string
    {
        [$from, $to] = $this->period();

        return 'Follow-ups due '.$from->format('d M Y').' – '.$to->format('d M Y').', by what became of them.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('from')
                    ->label('Due from')
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->required()
                    ->live(),
                DatePicker::make('to')
                    ->label('Due to')
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->required()
                    ->afterOrEqual('from')
                    ->live(),
                Select::make('employee_id')
                    ->label('Owner')
                    ->placeholder('Everyone')
                    ->options(fn (): array => EmployeeOptions::visibleTo())
                    ->live(),
            ])
            ->columns(3)
            ->statePath('filters');
    }

    public function updatedFilters(): void
    {
        $this->classification = null;
        unset($this->cachedTabs);

        $this->resetPage();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            // Frozen under the page header while the rows scroll (theme.css,
            // listing-sticky-header.js). The outcome tabs sit in the table
            // toolbar instead (AdminPanelProvider's TOOLBAR_START hook).
            Group::make([
                EmbeddedSchema::make('form'),
            ])->extraAttributes(['class' => 'fynn-frozen-listing-top']),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * Freezes the page like a resource listing: header, filters and tabs,
     * table toolbar and column header stay put; only the rows scroll.
     *
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fynn-frozen-listing-page'];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return collect(FollowUpOutcome::legendGroups())
            ->map(fn (array $group, string $key): Tab => Tab::make($group['label'])
                ->badge(fn (): string => number_format($this->outcomeCount($key)))
                ->badgeColor($group['color']))
            ->all();
    }

    /**
     * The outcome tabs as the pills in the table toolbar draw them; the hint
     * is the pill's tooltip.
     *
     * @return array<string, array{label: string, color: string, hint: string, count: int}>
     */
    public function outcomeTabs(): array
    {
        $hints = [
            'on_time' => 'Acted on within the grace period',
            'late' => 'Acted on after the grace period',
            'missed' => 'Not acted on and overdue',
            'open' => 'Due soon or still in grace',
        ];

        return collect(FollowUpOutcome::legendGroups())
            ->map(fn (array $group, string $key): array => [
                'label' => $group['label'],
                'color' => $group['color'],
                'hint' => $hints[$key] ?? '',
                'count' => $this->outcomeCount($key),
            ])
            ->all();
    }

    /**
     * How many follow-ups one tab holds, after the table's own filters.
     */
    public function outcomeCount(string $group): int
    {
        $ids = $this->idsFor($group);

        if ($ids === [] || ! $this->getTable()->isFiltered()) {
            return count($ids);
        }

        return $this->applyFiltersToTableQuery(FollowUp::query()->whereIn('follow_ups.id', $ids))->count();
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'missed';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => FollowUp::query()
                ->whereIn('follow_ups.id', $this->idsFor($this->activeTab))
                ->withFollowUpCount()
                ->with(['customer', 'aiCustomerRecord.schema', 'lead', 'employee']))
            ->defaultSort('next_follow_up_date', 'asc')
            ->emptyStateHeading('No follow-ups here')
            ->emptyStateDescription('Nothing with this outcome fell due in the chosen dates.')
            // Clicking a row shows the full mobile number; the listing itself
            // only ever shows the last four digits.
            ->recordAction('contact')
            ->columns([
                TextColumn::make('display_name')
                    ->label('Name')
                    ->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->whereHas('customer', fn (Builder $customer): Builder => $customer
                            ->where('customer_name', 'like', "%{$search}%")
                            ->orWhere('mobile_no', 'like', "%{$search}%"))
                        ->orWhereHas('lead', fn (Builder $lead): Builder => $lead
                            ->where('customer_name', 'like', "%{$search}%")
                            ->orWhere('mobile_no', 'like', "%{$search}%"))
                        ->orWhereHas('aiCustomerRecord', fn (Builder $record): Builder => $record
                            ->where('data', 'like', "%{$search}%"))))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                        'COALESCE((SELECT customer_name FROM customers WHERE customers.id = follow_ups.customer_id), '
                        .'(SELECT customer_name FROM leads WHERE leads.id = follow_ups.lead_id)) '
                        .($direction === 'desc' ? 'desc' : 'asc'),
                    )),

                TextColumn::make('mobile')
                    ->label('Mobile')
                    ->state(fn (FollowUp $record): ?string => static::mobileFor($record))
                    ->formatStateUsing(function (?string $state): string {
                        if (blank($state) || strlen($state) < 4) {
                            return $state ?? '-';
                        }

                        return 'XXXXXX'.substr($state, -4);
                    })
                    ->placeholder('-'),

                TextColumn::make('source')
                    ->label('Record')
                    ->badge()
                    ->color('gray')
                    ->state(fn (FollowUp $record): string => match (true) {
                        filled($record->customer_id) => 'Customer',
                        filled($record->ai_customer_record_id) => 'AI record',
                        default => 'Lead',
                    }),

                TextColumn::make('next_follow_up_date')
                    ->label('Due')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),

                TextColumn::make('outcome')
                    ->label('Outcome')
                    ->badge()
                    ->state(fn (FollowUp $record): ?string => $this->classificationFor($record)['outcome'] ?? null)
                    ->formatStateUsing(fn (?string $state): string => FollowUpOutcome::tryFrom((string) $state)?->label() ?? '-')
                    ->color(fn (?string $state): string => FollowUpOutcome::tryFrom((string) $state)?->color() ?? 'gray'),

                TextColumn::make('acted_at')
                    ->label('Acted on')
                    ->state(fn (FollowUp $record): ?string => $this->classificationFor($record)['acted_at'] ?? null)
                    ->dateTime('d M Y h:i A')
                    ->placeholder('Not yet'),

                TextColumn::make('follow_up_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn (?string $state): string => match ($state) {
                        'Interested', 'Eligible' => 'success',
                        'Pending', 'Call Back' => 'warning',
                        'Eligible for Other Bank' => 'info',
                        CustomerAssignment::BUREAU_CHECK_STATUS => 'primary',
                        'Not Interested', 'Not Eligible', 'Dropped', 'Lost' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('follow_up_count')
                    ->label('Follow-ups')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->sortable()
                    ->tooltip('How many times this prospect has been followed up in total'),

                TextColumn::make('employee.emp_name')
                    ->label('Owner')
                    ->placeholder('Admin')
                    ->description(fn (FollowUp $record): ?string => $record->employee?->emp_id)
                    ->sortable(),

                TextColumn::make('remarks')
                    ->limit(40)
                    ->tooltip(fn (FollowUp $record): ?string => $record->remarks)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Logged on')
                    ->dateTime('d M Y h:i A')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options(CustomerAssignment::FOLLOW_UP_STATUSES),

                SelectFilter::make('follow_up_type')
                    ->label('Type')
                    ->multiple()
                    ->options([
                        'Call' => 'Call',
                        'WhatsApp' => 'WhatsApp',
                        'Email' => 'Email',
                        'Visit' => 'Visit',
                    ]),

                SelectFilter::make('source')
                    ->label('Record')
                    ->multiple()
                    ->options(fn (): array => $this->recordSourceOptions())
                    ->visible(fn (): bool => count($this->recordSourceOptions()) > 1)
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? null),
                        fn (Builder $query): Builder => $query->where(function (Builder $query) use ($data): void {
                            foreach ($data['values'] as $source) {
                                match ($source) {
                                    'customer' => $query->orWhereNotNull('follow_ups.customer_id'),
                                    'ai_record' => $query->orWhereNotNull('follow_ups.ai_customer_record_id'),
                                    'lead' => $query->orWhereNotNull('follow_ups.lead_id'),
                                    default => null,
                                };
                            }
                        }),
                    )),

                SelectFilter::make('manager_id')
                    ->label('Manager')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::forDesignation(Employee::DESIGNATION_MANAGER))
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas(
                            'employee',
                            fn (Builder $employee): Builder => $employee->whereIn('manager_id', $data['values']),
                        ),
                    )),

                SelectFilter::make('team_leader_id')
                    ->label('Team Leader')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::forDesignation(Employee::DESIGNATION_TEAM_LEADER))
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas(
                            'employee',
                            fn (Builder $employee): Builder => $employee->whereIn('superviser_id', $data['values']),
                        ),
                    )),
            ])
            ->recordActions([
                Action::make('contact')
                    ->label('Contact')
                    ->icon('heroicon-o-phone')
                    ->color('gray')
                    ->modalHeading('Contact details')
                    ->modalWidth(Width::Medium)
                    ->schema([
                        TextEntry::make('display_name')
                            ->label('Name')
                            ->weight(FontWeight::Bold),
                        TextEntry::make('contact_mobile')
                            ->label('Mobile No')
                            ->state(fn (FollowUp $record): ?string => static::mobileFor($record))
                            ->url(fn (?string $state): ?string => filled($state) ? 'tel:'.preg_replace('/[^0-9+]/', '', $state) : null)
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('employee.emp_name')
                            ->label('Owner')
                            ->placeholder('Admin'),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->extraModalFooterActions(fn (FollowUp $record): array => filled($recordUrl = FollowUpRecordUrl::for($record))
                        ? [
                            Action::make('openRecord')
                                ->label('Open record')
                                ->icon('heroicon-m-arrow-top-right-on-square')
                                ->url($recordUrl),
                        ]
                        : []),

                Action::make('log')
                    ->label('Log')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (FollowUp $record): string => 'Follow-up log — '.$record->display_name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (FollowUp $record) => view(
                        'filament.follow-ups.history',
                        ['history' => $record->historyForSubject(), 'current' => $record],
                    )),
            ]);
    }

    /**
     * The prospect's mobile number, from whichever record the follow-up is
     * tied to.
     */
    protected static function mobileFor(FollowUp $record): ?string
    {
        return $record->customer?->mobile_no
            ?? $record->lead?->mobile_no
            ?? $record->aiCustomerRecord?->prospectValue('mobile_number');
    }

    /**
     * The due-date range on screen. A blank or unreadable date falls back to
     * the selected month; a "to" before "from" is read as "from".
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function period(): array
    {
        [$defaultFrom, $defaultTo] = $this->defaultPeriod();

        $from = DueDateInput::parse($this->filters['from'] ?? null) ?? $defaultFrom;
        $to = DueDateInput::parse($this->filters['to'] ?? null) ?? $defaultTo;

        if ($to->lt($from)) {
            $to = $from->copy();
        }

        return [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
    }

    /**
     * Ids of the follow-ups in one verdict group (a key of
     * FollowUpOutcome::legendGroups()); none for an unknown key.
     *
     * @return list<int>
     */
    public function idsFor(string|int|null $group): array
    {
        return $this->classification()['groups'][$group] ?? [];
    }

    /**
     * @return array{outcome: string, acted_at: ?string}|null
     */
    protected function classificationFor(FollowUp $followUp): ?array
    {
        return $this->classification()['rows'][$followUp->id] ?? null;
    }

    /**
     * @return array{groups: array<string, list<int>>, rows: array<int, array{outcome: string, acted_at: ?string}>}
     */
    protected function classification(): array
    {
        if ($this->classification !== null) {
            return $this->classification;
        }

        [$from, $to] = $this->period();
        $employeeId = $this->employeeFilter();

        return $this->classification = FollowUpCalendarCache::remember(
            static::class.'@'.($employeeId ?? 'everyone'),
            Filament::auth()->id(),
            $from->toDateTimeString(),
            $to->toDateTimeString(),
            fn (): array => $this->classify($from, $to, $employeeId),
        );
    }

    /**
     * Judged on the few columns classify() needs; the table loads the rest
     * for the page on screen only.
     *
     * @return array{groups: array<string, list<int>>, rows: array<int, array{outcome: string, acted_at: ?string}>}
     */
    protected function classify(Carbon $from, Carbon $to, ?int $employeeId): array
    {
        $columns = collect(['id', 'employee_id', 'next_follow_up_date', 'created_at', ...FollowUp::SUBJECT_COLUMNS])
            ->map(fn (string $column): string => 'follow_ups.'.$column)
            ->all();

        $query = $this->visibleFollowUpQuery()->select($columns);

        if ($employeeId !== null) {
            $query->where('follow_ups.employee_id', $employeeId);
        }

        $classified = app(FollowUpMonitorService::class)->classify($query, $from, $to);

        return [
            'groups' => collect(FollowUpOutcome::legendGroups())
                ->map(fn (array $group): array => $classified
                    ->filter(fn (FollowUp $followUp): bool => in_array($followUp->monitor_outcome, $group['outcomes'], true))
                    ->map(fn (FollowUp $followUp): int => (int) $followUp->id)
                    ->values()
                    ->all())
                ->all(),
            'rows' => $classified
                ->mapWithKeys(fn (FollowUp $followUp): array => [(int) $followUp->id => [
                    'outcome' => $followUp->monitor_outcome->value,
                    'acted_at' => $followUp->acted_at?->toDateTimeString(),
                ]])
                ->all(),
        ];
    }

    /**
     * The month the calendars open on.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function defaultPeriod(): array
    {
        $month = SelectedMonth::current();

        return [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
    }

    protected function employeeFilter(): ?int
    {
        return filled($this->filters['employee_id'] ?? null) ? (int) $this->filters['employee_id'] : null;
    }
}
