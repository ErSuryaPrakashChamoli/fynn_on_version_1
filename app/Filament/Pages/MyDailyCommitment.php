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
 * the answer by 18:30, and DailyCommitmentGate shuts the rest of the
 * panel behind either deadline (see App\Http\Middleware\EnsureDailyCommitmentIsDeclared).
 * This screen is the only way out of that block, so it is deliberately
 * the one page the prompt never covers.
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

    /** Reason attached to a nil day — a compulsory declaration cannot be silent. */
    public ?string $nothingReason = null;

    public function mount(): void
    {
        $this->date = today()->toDateString();
        $this->fillFromCommitment();
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
                    ->itemLabel(fn (array $state): ?string => $state['customer_name'] ?? null)
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

                        Select::make('stage')
                            ->label('Stage reached')
                            ->options(CommitmentStage::ladderOptions())
                            ->native(false)
                            ->required()
                            ->columnSpan(1),

                        TextInput::make('amount')
                            ->label('Amount (₹)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->live(onBlur: true)
                            ->helperText(fn ($state): ?string => filled($state)
                                ? indianAmount($state)
                                : null)
                            ->columnSpan(1),

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
     * @return array<int, string>
     */
    protected function searchCustomers(string $search): array
    {
        $employee = Filament::auth()->user()?->employee;

        if (! $employee) {
            return [];
        }

        return $this->customerScope($employee)
            ->where(function ($query) use ($search) {
                $query->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('mobile_no', 'like', "%{$search}%")
                    ->orWhere('application_no', 'like', "%{$search}%")
                    ->orWhere('pan_number', 'like', "%{$search}%");
            })
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'customer_name', 'application_no'])
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

        if (blank($this->nothingReason) || mb_strlen(trim($this->nothingReason)) < 10) {
            Notification::make()
                ->title('Say what happened')
                ->body('A nil day needs a reason of at least 10 characters.')
                ->warning()
                ->send();

            return;
        }

        $commitment->entries()->delete();

        $commitment->forceFill([
            'submitted_at' => now(),
            'declaration_note' => trim($this->nothingReason),
        ])->save();

        app(DailyCommitmentService::class)->syncCommitment($commitment->refresh());

        app(DailyCommitmentGate::class)->forget();

        $this->nothingReason = null;

        Notification::make()->title('Nil day recorded')->success()->send();

        $this->fillFromCommitment();
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
        // asks for a reason — so an empty list can never quietly close a
        // commitment.
        if ($submit && $rows === []) {
            Notification::make()
                ->title('Name the cases first')
                ->body('Add the customers that make up today\'s business, or record a nil day with a reason.')
                ->warning()
                ->send();

            return;
        }

        // Only cases the employee may actually claim, and the LMS's own
        // highest stage is always resolved server-side — a client can
        // never inflate a row past what the journey supports.
        $allowedCustomerIds = $employee
            ? $this->customerScope($employee)->pluck('id')->all()
            : [];

        $resolved = app(DailyCommitmentService::class)->highestStageFor(
            collect($rows)->pluck('customer_id')->filter()->map(fn ($id): int => (int) $id)
        );

        $commitment->entries()->delete();

        foreach ($rows as $row) {
            $customerId = filled($row['customer_id'] ?? null) ? (int) $row['customer_id'] : null;

            if ($customerId !== null && ! in_array($customerId, $allowedCustomerIds, true)) {
                $customerId = null;
            }

            DailyCommitmentEntry::create([
                'daily_commitment_id' => $commitment->id,
                'customer_id' => $customerId,
                'customer_name' => $row['customer_name'],
                'reference' => $row['reference'] ?? null,
                'stage' => $row['stage'],
                'lms_highest_stage' => $customerId ? ($resolved[$customerId]['stage']?->value) : null,
                'outcome' => $row['outcome'] ?? null,
                'amount' => (float) ($row['amount'] ?? 0),
                'remarks' => $row['remarks'] ?? null,
            ]);
        }

        if ($submit) {
            $commitment->forceFill([
                'submitted_at' => now(),
                'declaration_note' => null,
            ])->save();
        }

        app(DailyCommitmentService::class)->syncCommitment($commitment->refresh());

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
     * What the gate is holding this user on right now, if anything — the
     * banner at the top of the page is the same block the rest of the
     * panel is showing them.
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
     * The day's declared business split at the committed stage: what
     * counts in full, and what came in below it. This is the headline the
     * employee reads at 18:30 — "₹7L at Approval, ₹3L below it".
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

        $achievement = app(DailyCommitmentService::class)->achievementFromEntries($entries, $stage);
        $breakdown = app(DailyCommitmentService::class)->entryBreakdown($entries);

        $stages = [];

        // Highest rung first: the ladder reads top-down here because the
        // committed stage is what the eye should land on.
        foreach (array_reverse(CommitmentStage::ladder()) as $rung) {
            $totals = $breakdown['stages'][$rung->value] ?? ['amount' => 0.0, 'count' => 0];

            $stages[$rung->value] = [
                'amount' => (float) $totals['amount'],
                'count' => (int) $totals['count'],
                'counts' => ($rung->rank() ?? 0) >= ($stage->rank() ?? 0),
            ];
        }

        return [
            // Read live from the declared rows, not from the last saved
            // snapshot — this strip has to move as the employee types.
            'target' => $commitment->target(),
            'at_or_above' => $stage->isCount() ? (float) $achievement['count'] : $achievement['amount'],
            'below' => $stage->isCount() ? 0.0 : $achievement['below_amount'],
            'total' => $stage->isCount() ? (float) $achievement['count'] : $achievement['total_amount'],
            'is_count' => $stage->isCount(),
            'stage' => $stage,
            'stages' => $stages,
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
