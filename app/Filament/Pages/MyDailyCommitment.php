<?php

namespace App\Filament\Pages;

use App\Enums\CommitmentResult;
use App\Enums\CommitmentStage;
use App\Models\Customer;
use App\Models\DailyCommitment;
use App\Models\DailyCommitmentEntry;
use App\Models\DailyCommitmentLog;
use App\Models\Employee;
use App\Services\DailyCommitmentGate;
use App\Services\DailyCommitmentService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The salesperson's own screen, in two halves:
 *
 *  MORNING — "what do you commit to?" A stage and a number. No customer
 *  is named here; the commitment is just a promise.
 *
 *  END OF DAY — "what did you actually bring?" The employee lists the
 *  customers/cases that make up the day's business and submits the final
 *  status. Achievement is computed only from those declared rows, so
 *  historical business sitting in the LMS can never drift into today.
 *
 * Both halves are compulsory and timed: the promise is due by 09:50 and
 * the answer by 18:30, and DailyCommitmentGate raises a compulsory prompt
 * past either deadline. That prompt can take both answers itself; this
 * screen is the fuller version of the same two steps, and is the one page
 * the prompt never covers.
 *
 * Neither closes the rest of the LMS.
 *
 * A day can be fulfilled in parts: ₹10L promised at Approval may come
 * back as ₹7L Approval + ₹3L SFL. Only business at or above the promised
 * stage earns a clean pass; the rest still counts, as PARTIALLY MET.
 */
class MyDailyCommitment extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Daily Commitment';

    protected static ?string $navigationLabel = 'My Commitment';

    protected static ?string $title = 'My Daily Commitment';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.daily-commitment.my-commitment';

    public ?array $data = [];

    public ?array $fulfilment = [];

    public string $date;

    /** Optional note attached to a failed day. */
    public ?string $nothingReason = null;

    /**
     * Which of the two ways the day is being closed: 'cases' when there is
     * business to name, 'failed' when there is not. Null until the
     * employee has said which — the customer list is only asked for once
     * they have said they have something to feed into it.
     */
    public ?string $declarationMode = null;

    /**
     * The stage-by-stage zeros on a failed day, keyed by stage value.
     *
     * Meeting the commitment is not compulsory — declaring the outcome
     * is. So a day with nothing on it is closed by stating a figure
     * against every rung rather than by a single vague "nothing": the
     * zeros are the declaration.
     *
     * @var array<string, string|null>
     */
    public array $nilStages = [];

    public function mount(): void
    {
        $this->date = today()->toDateString();
        $this->primeNilStages();
        $this->fillFromCommitment();
    }

    /**
     * Every rung starts at zero, ready to be confirmed.
     */
    protected function primeNilStages(): void
    {
        $this->nilStages = collect(CommitmentStage::ladder())
            ->mapWithKeys(fn (CommitmentStage $stage): array => [$stage->value => '0'])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Morning commitment — stage and number only, never a customer
    |--------------------------------------------------------------------------
    */

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('date')
                    ->label('Commitment date')
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->maxDate(now()->endOfDay())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state): void {
                        $this->date = Carbon::parse($state)->toDateString();
                        $this->fillFromCommitment();
                    }),

                Select::make('commitment_stage')
                    ->label('I commit to')
                    ->options(CommitmentStage::commitableOptions())
                    ->native(false)
                    ->default(CommitmentStage::default()->value)
                    ->required()
                    ->disabled(fn (): bool => $this->isCommitmentLocked())
                    ->live(),

                TextInput::make('commitment_amount')
                    ->label('Amount (₹)')
                    ->numeric()
                    ->minValue(1)
                    ->required(fn (Get $get): bool => $get('commitment_stage') !== CommitmentStage::Otp->value)
                    ->visible(fn (Get $get): bool => $get('commitment_stage') !== CommitmentStage::Otp->value)
                    ->disabled(fn (): bool => $this->isCommitmentLocked())
                    // The figure is read straight back in Indian grouping
                    // and in words — a commitment is locked once given, so
                    // a stray zero has to be caught before it is saved.
                    ->live(onBlur: true)
                    ->helperText(fn ($state): string => filled($state)
                        ? indianAmount($state).' — '.indianAmountInWords($state)
                        : 'e.g. 1000000 for ₹10,00,000'),

                TextInput::make('commitment_count')
                    ->label('Number of OTPs')
                    ->numeric()
                    ->minValue(1)
                    ->required(fn (Get $get): bool => $get('commitment_stage') === CommitmentStage::Otp->value)
                    ->visible(fn (Get $get): bool => $get('commitment_stage') === CommitmentStage::Otp->value)
                    ->disabled(fn (): bool => $this->isCommitmentLocked()),

                Textarea::make('remarks')
                    ->label('Remarks (optional)')
                    ->rows(2)
                    ->maxLength(500)
                    ->disabled(fn (): bool => $this->isCommitmentLocked())
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->statePath('data');
    }

    /*
    |--------------------------------------------------------------------------
    | End of day — customer-wise fulfilment
    |--------------------------------------------------------------------------
    */

    public function fulfilmentForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Repeater::make('entries')
                    ->label('Customers / cases')
                    ->addActionLabel('Add customer')
                    ->reorderable(false)
                    ->columns(6)
                    ->defaultItems(0)
                    ->itemLabel(fn (array $state): ?string => trim(
                        ($state['customer_name'] ?? '').(filled($state['mobile_no'] ?? null) ? ' — '.$state['mobile_no'] : '')
                    ) ?: null)
                    ->schema([
                        Select::make('customer_id')
                            ->label('Customer (from LMS)')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchCustomers($search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->customerLabel((int) $value))
                            ->live()
                            // Picking an LMS case fills in everything the LMS
                            // already knows, including the highest stage it
                            // ever reached — the employee only overrides it.
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $customer = Customer::find((int) $state);

                                if (! $customer) {
                                    return;
                                }

                                $resolved = app(DailyCommitmentService::class)
                                    ->highestStageFor(collect([$customer->id]))[$customer->id] ?? null;

                                $set('customer_name', $customer->customer_name);
                                $set('mobile_no', DailyCommitmentEntry::normaliseMobile($customer->mobile_no));
                                $set('reference', $customer->application_no ?? $customer->lan_no);
                                $set('stage', $resolved['stage']?->value);
                                $set('outcome', $resolved['outcome']?->value);
                                $set('amount', $resolved['amount'] ? (int) $resolved['amount'] : null);
                            })
                            ->columnSpan(2),

                        TextInput::make('customer_name')
                            ->label('Customer name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        // The number is what identifies the case. It is
                        // checked as it is typed, so a customer somebody
                        // has already claimed is caught here rather than
                        // on submit with the whole day filled in.
                        TextInput::make('mobile_no')
                            ->label('Mobile number')
                            ->tel()
                            ->required()
                            ->maxLength(20)
                            ->live(onBlur: true)
                            ->rule(fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                                $this->failIfMobileIsTaken($value, $fail);
                            })
                            ->helperText('Claimed once — a customer counted on any commitment cannot be counted again.')
                            ->columnSpan(2),

                        Select::make('stage')
                            ->label('Stage reached')
                            ->options(CommitmentStage::ladderOptions())
                            ->native(false)
                            ->required()
                            ->columnSpan(3),

                        TextInput::make('amount')
                            ->label('Amount (₹)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->live(onBlur: true)
                            ->helperText(fn ($state): ?string => filled($state)
                                ? indianAmount($state)
                                : null)
                            ->columnSpan(3),

                        // Everything below is the exception, not the rule:
                        // four fields close a normal case, and the rest
                        // stays folded away so 18:30 is not a form-filling
                        // exercise.
                        Section::make('More detail')
                            ->collapsed()
                            ->columns(2)
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('reference')
                                    ->label('Lead / Application ID')
                                    ->maxLength(255),

                                Select::make('outcome')
                                    ->label('Outcome')
                                    ->options(CommitmentStage::outcomeOptions())
                                    ->placeholder('Still live')
                                    ->native(false)
                                    ->helperText('Dropped/Rejected do not undo the stage already reached.'),

                                Textarea::make('remarks')
                                    ->label('Remarks')
                                    ->rows(1)
                                    ->maxLength(500)
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ])
            ->statePath('fulfilment');
    }

    /**
     * Fail validation when this number has already been declared on some
     * other commitment — anybody's, on any day. The message names the
     * claim so the employee can go and sort it out rather than guessing.
     */
    protected function failIfMobileIsTaken(?string $value, \Closure $fail): void
    {
        $claim = DailyCommitmentEntry::claimFor($value, $this->commitment?->id);

        if (! $claim) {
            return;
        }

        $owner = $claim->commitment?->employee?->emp_name;
        $on = $claim->commitment?->date?->format('d M Y');

        $fail(
            trim(sprintf(
                'This customer has already been counted%s%s. A mobile number can only be claimed once.',
                $owner ? ' by '.$owner : '',
                $on ? ' on '.$on : '',
            ))
        );
    }

    /**
     * Cases already claimed on some other commitment are dropped from the
     * picker — the number can only be counted once, so offering them
     * would only lead to a rejected save.
     *
     * @return array<int, string>
     */
    protected function searchCustomers(string $search): array
    {
        $employee = Filament::auth()->user()?->employee;

        if (! $employee) {
            return [];
        }

        $claimed = DailyCommitmentEntry::query()
            ->whereNotNull('mobile_no')
            ->when($this->commitment, fn ($query) => $query->where('daily_commitment_id', '!=', $this->commitment->id))
            ->pluck('mobile_no')
            ->all();

        return $this->customerScope($employee)
            ->where(function ($query) use ($search) {
                $query->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('mobile_no', 'like', "%{$search}%")
                    ->orWhere('application_no', 'like', "%{$search}%")
                    ->orWhere('pan_number', 'like', "%{$search}%");
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'customer_name', 'application_no', 'mobile_no'])
            // Normalising both sides is the only reliable comparison, and
            // no portable SQL does it — so the claimed set is matched in
            // PHP rather than in a driver-specific expression.
            ->reject(fn (Customer $customer): bool => in_array(
                DailyCommitmentEntry::normaliseMobile($customer->mobile_no),
                $claimed,
                true,
            ))
            ->take(30)
            ->mapWithKeys(fn (Customer $customer): array => [
                $customer->id => trim($customer->customer_name.' ('.($customer->application_no ?? $customer->id).')'),
            ])
            ->all();
    }

    protected function customerLabel(int $customerId): ?string
    {
        $customer = Customer::find($customerId);

        return $customer
            ? trim($customer->customer_name.' ('.($customer->application_no ?? $customer->id).')')
            : null;
    }

    /**
     * Cases an employee may claim: their own book. A Team Leader or above
     * may also claim anything in their reporting tree.
     */
    protected function customerScope(Employee $employee)
    {
        $ids = $employee->designation === Employee::DESIGNATION_CALLER
            ? collect([$employee->id])
            : app(DailyCommitmentService::class)->visibleEmployeeIds(Filament::auth()->user());

        return Customer::query()->whereIn('employee_id', $ids);
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    /**
     * Save (or revise) the morning commitment. A revision writes an
     * old/new value log row — that is the whole audit story here.
     */
    public function save(): void
    {
        $employee = Filament::auth()->user()?->employee;

        if (! $employee) {
            Notification::make()->title('No employee profile is linked to your account.')->danger()->send();

            return;
        }

        $state = $this->form->getState();
        $date = Carbon::parse($state['date'])->startOfDay();

        $commitment = DailyCommitment::query()
            ->where('employee_id', $employee->id)
            ->forDate($date)
            ->first();

        // A commitment is a promise: once given it is fixed. Enforced here
        // as well as on the disabled fields, so a crafted request cannot
        // rewrite the morning number after the fact.
        if ($commitment && ! $commitment->isEditableBy(Filament::auth()->user())) {
            Notification::make()
                ->title('This commitment is locked')
                ->body('A commitment cannot be changed once given. Ask an Admin if it needs correcting.')
                ->danger()
                ->send();

            $this->fillFromCommitment();

            return;
        }

        $stage = CommitmentStage::from($state['commitment_stage']);

        $amount = $stage->isCount() ? 0 : (float) ($state['commitment_amount'] ?? 0);
        $count = $stage->isCount() ? (int) ($state['commitment_count'] ?? 0) : 0;

        if ($commitment) {
            $unchanged = $commitment->commitment_stage === $stage
                && round((float) $commitment->commitment_amount, 2) === round($amount, 2)
                && (int) $commitment->commitment_count === $count;

            if (! $unchanged) {
                DailyCommitmentLog::create([
                    'daily_commitment_id' => $commitment->id,
                    'employee_id' => $employee->id,
                    'old_stage' => $commitment->commitment_stage->value,
                    'new_stage' => $stage->value,
                    'old_amount' => $commitment->commitment_amount,
                    'new_amount' => $amount,
                    'old_count' => $commitment->commitment_count,
                    'new_count' => $count,
                    'change_type' => 'commitment',
                    'note' => 'Commitment revised.',
                ]);
            }

            $commitment->update([
                'commitment_stage' => $stage,
                'commitment_amount' => $amount,
                'commitment_count' => $count,
                'remarks' => $state['remarks'] ?? null,
            ]);
        } else {
            $commitment = DailyCommitment::create([
                'employee_id' => $employee->id,
                'date' => $date,
                'commitment_stage' => $stage,
                'commitment_amount' => $amount,
                'commitment_count' => $count,
                'result' => CommitmentResult::InProgress,
                'remarks' => $state['remarks'] ?? null,
                'created_by' => Filament::auth()->id(),
            ]);
        }

        app(DailyCommitmentService::class)->syncCommitment($commitment);

        app(DailyCommitmentGate::class)->forget();

        Notification::make()
            ->title('Commitment saved')
            ->body('Declare what you achieved against it before '.DailyCommitmentGate::EVENING_DEADLINE.'.')
            ->success()
            ->send();

        $this->fillFromCommitment();
    }

    /**
     * Persist the customer-wise fulfilment without closing the day.
     */
    public function saveFulfilment(): void
    {
        $this->persistFulfilment(submit: false);
    }

    /**
     * Persist and close the day.
     */
    public function submitFinalStatus(): void
    {
        $this->persistFulfilment(submit: true);
    }

    /**
     * Close the day with nothing on it.
     *
     * The 18:30 declaration is compulsory, so a blank day still has to be
     * stated rather than left silent — and stating it costs a reason,
     * which is what stops "nothing today" being the quick way past the
     * block.
     */
    public function declareNothing(): void
    {
        $commitment = $this->commitment;

        if (! $commitment) {
            Notification::make()->title('Give your morning commitment first.')->warning()->send();

            return;
        }

        // The zeros are the declaration, so they have to actually be
        // zeros. Anything real belongs on a named case with a mobile
        // number against it, which is the other path entirely.
        foreach (CommitmentStage::ladder() as $rung) {
            if ((float) ($this->nilStages[$rung->value] ?? 0) > 0) {
                Notification::make()
                    ->title('That is not a failed day')
                    ->body("You have entered business against {$rung->label()}. Declare it as a case instead, with the customer and mobile number.")
                    ->warning()
                    ->send();

                return;
            }
        }

        $commitment->entries()->delete();

        $commitment->forceFill([
            'submitted_at' => now(),
            'declaration_note' => filled($this->nothingReason) ? trim($this->nothingReason) : null,
        ])->save();

        app(DailyCommitmentService::class)->syncCommitment($commitment->refresh());

        app(DailyCommitmentGate::class)->forget();

        $this->nothingReason = null;

        Notification::make()
            ->title('Commitment recorded as failed')
            ->body('Nothing at any stage today. The rest of the LMS is open again.')
            ->success()
            ->send();

        $this->fillFromCommitment();
    }

    /**
     * Say whether there is anything to declare. Choosing "cases" is what
     * opens the customer list; choosing "failed" opens the stage-wise
     * zeros instead. Neither is shown until one is chosen, so nobody is
     * handed a customer form for a day that had no customers.
     */
    public function chooseDeclarationMode(?string $mode): void
    {
        $this->declarationMode = in_array($mode, ['cases', 'failed'], true) ? $mode : null;
    }

    public function reopenFinalStatus(): void
    {
        $commitment = $this->commitment;

        if (! $commitment) {
            return;
        }

        $commitment->forceFill(['submitted_at' => null])->save();

        app(DailyCommitmentService::class)->syncCommitment($commitment);

        app(DailyCommitmentGate::class)->forget();

        Notification::make()->title('Final status reopened')->success()->send();

        $this->fillFromCommitment();
    }

    protected function persistFulfilment(bool $submit): void
    {
        $commitment = $this->commitment;

        if (! $commitment) {
            Notification::make()->title('Give your morning commitment first.')->warning()->send();

            return;
        }

        $employee = Filament::auth()->user()?->employee;
        $rows = $this->fulfilmentForm->getState()['entries'] ?? [];

        // Every rupee declared has to be backed by a named case. A day
        // with nothing on it goes through declareNothing() instead, which
        // records a zero against every stage — so an empty list can never
        // quietly close a commitment.
        if ($submit && $rows === []) {
            Notification::make()
                ->title('Name the cases first')
                ->body('Add the customers that make up today\'s business, or go back and record the commitment as failed.')
                ->warning()
                ->send();

            return;
        }

        $service = app(DailyCommitmentService::class);

        // Only cases the employee may actually claim: one outside their own
        // book is kept as a typed row but never linked, so it can never
        // inherit that case's LMS stage.
        $allowedCustomerIds = $employee
            ? $this->customerScope($employee)->pluck('id')->all()
            : [];

        $rows = collect($rows)->map(function (array $row) use ($allowedCustomerIds): array {
            $customerId = filled($row['customer_id'] ?? null) ? (int) $row['customer_id'] : null;

            $row['customer_id'] = ($customerId !== null && in_array($customerId, $allowedCustomerIds, true))
                ? $customerId
                : null;

            return $row;
        })->all();

        // Checked BEFORE anything is written: replaceFulfilment rebuilds the
        // rows by deleting and re-inserting, so a rejection discovered
        // halfway through would take the day's work with it. The rule itself
        // lives in the service — the prompt and the Admin correction screen
        // enforce the same one.
        $reason = $service->reasonMobilesCannotBeSaved($rows, $commitment->id);

        if ($reason !== null) {
            Notification::make()->title('Check the mobile numbers')->body($reason)->danger()->send();

            return;
        }

        $service->replaceFulfilment($commitment, $rows, submit: $submit);

        app(DailyCommitmentGate::class)->forget();

        Notification::make()
            ->title($submit ? 'Final status submitted' : 'Fulfilment saved')
            ->success()
            ->send();

        $this->fillFromCommitment();
    }

    /*
    |--------------------------------------------------------------------------
    | View data
    |--------------------------------------------------------------------------
    */

    /**
     * True once a commitment exists for the selected date and the current
     * user is not allowed to change it.
     */
    public function isCommitmentLocked(): bool
    {
        $commitment = $this->commitment;

        return $commitment !== null && ! $commitment->isEditableBy(Filament::auth()->user());
    }

    public function getCommitmentProperty(): ?DailyCommitment
    {
        $employee = Filament::auth()->user()?->employee;

        if (! $employee) {
            return null;
        }

        return DailyCommitment::query()
            ->where('employee_id', $employee->id)
            ->forDate(Carbon::parse($this->date))
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRowProperty(): ?array
    {
        $employee = Filament::auth()->user()?->employee;

        if (! $employee) {
            return null;
        }

        return app(DailyCommitmentService::class)
            ->dailyRows(collect([$employee->id]), Carbon::parse($this->date))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMonthlyProperty(): array
    {
        $employee = Filament::auth()->user()?->employee;

        if (! $employee) {
            return [];
        }

        return app(DailyCommitmentService::class)
            ->monthlyPosition($employee->id, Carbon::parse($this->date));
    }

    /**
     * What, if anything, the gate is holding this user on — the same
     * question the prompt asks, so the banner here cannot disagree with it.
     *
     * @return array{blocked: bool, reason: ?string, date: ?Carbon, commitment: ?DailyCommitment, overdue: bool}
     */
    public function getGateStatusProperty(): array
    {
        $user = Filament::auth()->user();

        return $user
            ? app(DailyCommitmentGate::class)->status($user)
            : ['blocked' => false, 'reason' => null, 'date' => null, 'commitment' => null, 'overdue' => false];
    }

    public function getGateMessageProperty(): string
    {
        $status = $this->gateStatus;

        return $status['blocked']
            ? app(DailyCommitmentGate::class)->message($status)
            : '';
    }

    /**
     * The day's declared business split at the committed stage: what counts
     * in full, and what came in below it. Read live from the declared rows
     * rather than the saved snapshot, so the strip moves as the employee
     * types.
     *
     * @return array{target: float, at_or_above: float, below: float, total: float, is_count: bool, stage: ?CommitmentStage, stages: array<string, array{amount: float, count: int, counts: bool}>}
     */
    public function getSplitProperty(): array
    {
        $commitment = $this->commitment;

        $blank = [
            'target' => 0.0,
            'at_or_above' => 0.0,
            'below' => 0.0,
            'total' => 0.0,
            'is_count' => false,
            'stage' => null,
            'stages' => [],
        ];

        if (! $commitment) {
            return $blank;
        }

        $stage = $commitment->commitment_stage;
        $entries = $this->entries;

        $service = app(DailyCommitmentService::class);

        $achievement = $service->achievementFromEntries($entries, $stage);
        $breakdown = $service->entryBreakdown($entries);

        $stages = [];

        // Highest rung first: the committed stage is what the eye should
        // land on.
        foreach (array_reverse(CommitmentStage::ladder()) as $rung) {
            $totals = $breakdown['stages'][$rung->value] ?? ['amount' => 0.0, 'count' => 0];

            $stages[$rung->value] = [
                'amount' => (float) $totals['amount'],
                'count' => (int) $totals['count'],
                'counts' => ($rung->rank() ?? 0) >= ($stage->rank() ?? 0),
            ];
        }

        return [
            'target' => $commitment->target(),
            'at_or_above' => $stage->isCount() ? (float) $achievement['count'] : $achievement['amount'],
            'below' => $stage->isCount() ? 0.0 : $achievement['below_amount'],
            'total' => $stage->isCount() ? (float) $achievement['count'] : $achievement['total_amount'],
            'is_count' => $stage->isCount(),
            'stage' => $stage,
            'stages' => $stages,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Commitment history — day by day
    |--------------------------------------------------------------------------
    */

    /** Period preset from DailyCommitmentService::rangeOptions(). */
    public string $historyRange = 'this_month';

    public ?string $historyFrom = null;

    public ?string $historyTo = null;

    /** A CommitmentResult value, or 'all'. */
    public string $historyResult = 'all';

    /**
     * The day-by-day history for the signed-in employee.
     *
     * `tally` is counted over the whole range and `filtered` is what the
     * table lists — narrowing to "Failed" must not make the headline read
     * "0 met".
     *
     * @return array{rows: Collection<int, array<string, mixed>>, filtered: Collection<int, array<string, mixed>>, tally: array<string, mixed>}
     */
    public function getHistoryProperty(): array
    {
        $service = app(DailyCommitmentService::class);

        $employeeId = Filament::auth()->user()?->employee?->id;

        if (! $employeeId) {
            return ['rows' => collect(), 'filtered' => collect(), 'tally' => $service->historyTally(collect())];
        }

        [$start, $end] = DailyCommitmentService::resolveRange(
            $this->historyRange,
            $this->historyFrom,
            $this->historyTo,
        );

        $rows = $service->dayByDayHistory($employeeId, $start, $end);

        $result = CommitmentResult::tryFrom($this->historyResult);

        return [
            'rows' => $rows,
            'filtered' => $result ? $rows->where('result', $result)->values() : $rows,
            'tally' => $service->historyTally($rows),
        ];
    }

    /**
     * @return Collection<int, DailyCommitmentEntry>
     */
    public function getEntriesProperty(): Collection
    {
        return $this->commitment?->entries()->get() ?? collect();
    }

    public function getLogsProperty(): Collection
    {
        return $this->commitment?->logs()->limit(20)->get() ?? collect();
    }

    protected function fillFromCommitment(): void
    {
        $commitment = $this->commitment;

        // A day that already has cases on it is plainly the "cases" path;
        // otherwise the employee has not said yet, and is asked.
        $this->declarationMode = $commitment?->entries()->exists() ? 'cases' : null;

        $this->form->fill([
            'date' => $this->date,
            // Disbursal unless this day already carries a commitment.
            'commitment_stage' => $commitment?->commitment_stage->value ?? CommitmentStage::default()->value,
            'commitment_amount' => $commitment && ! $commitment->commitment_stage->isCount()
                ? (int) $commitment->commitment_amount
                : null,
            'commitment_count' => $commitment?->commitment_stage->isCount()
                ? $commitment->commitment_count
                : null,
            'remarks' => $commitment?->remarks,
        ]);

        $this->fulfilmentForm->fill([
            'entries' => $commitment
                ? $commitment->entries()->get()->map(fn (DailyCommitmentEntry $entry): array => [
                    'customer_id' => $entry->customer_id,
                    'customer_name' => $entry->customer_name,
                    'mobile_no' => $entry->mobile_no,
                    'reference' => $entry->reference,
                    'stage' => $entry->stage->value,
                    'outcome' => $entry->outcome?->value,
                    'amount' => (int) $entry->amount,
                    'remarks' => $entry->remarks,
                ])->all()
                : [],
        ]);
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->employee;
    }
}
