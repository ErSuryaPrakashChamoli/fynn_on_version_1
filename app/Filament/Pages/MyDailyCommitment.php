<?php

namespace App\Filament\Pages;

use App\Enums\CommitmentResult;
use App\Enums\CommitmentStage;
use App\Models\Customer;
use App\Models\DailyCommitment;
use App\Models\DailyCommitmentEntry;
use App\Models\DailyCommitmentLog;
use App\Models\Employee;
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
                    ->helperText('e.g. 1000000 for ₹10 L'),

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

                        TextInput::make('reference')
                            ->label('Lead / Application ID')
                            ->maxLength(255)
                            ->columnSpan(2),

                        Select::make('stage')
                            ->label('Stage reached')
                            ->options(CommitmentStage::ladderOptions())
                            ->native(false)
                            ->required()
                            ->columnSpan(2),

                        Select::make('outcome')
                            ->label('Outcome')
                            ->options(CommitmentStage::outcomeOptions())
                            ->placeholder('Still live')
                            ->native(false)
                            ->helperText('Dropped/Rejected do not undo the stage already reached.')
                            ->columnSpan(2),

                        TextInput::make('amount')
                            ->label('Amount (₹)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->columnSpan(2),

                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->rows(1)
                            ->maxLength(500)
                            ->columnSpanFull(),
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

        Notification::make()->title('Commitment saved')->success()->send();

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

    public function reopenFinalStatus(): void
    {
        $commitment = $this->commitment;

        if (! $commitment) {
            return;
        }

        $commitment->forceFill(['submitted_at' => null])->save();

        app(DailyCommitmentService::class)->syncCommitment($commitment);

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
            $commitment->forceFill(['submitted_at' => now()])->save();
        }

        app(DailyCommitmentService::class)->syncCommitment($commitment->refresh());

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
            'commitment_stage' => $commitment?->commitment_stage->value,
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
