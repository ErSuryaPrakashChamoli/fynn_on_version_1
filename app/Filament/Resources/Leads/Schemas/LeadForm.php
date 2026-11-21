<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Models\Bank;
use App\Models\City;
use App\Models\CustomerAssignment;
use App\Models\CustomerPanRequest;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use App\Rules\PanIsAvailable;
use App\Services\HierarchyService;
use App\Services\PanDuplicateService;
use App\Support\EmployeeOptions;
use App\Support\PanMatch;
use Coolsam\Flatpickr\Forms\Components\Flatpickr;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //

                Section::make('Prospect Details (Manual Entry)')
                    ->icon(Heroicon::OutlinedUser)
                    ->schema([

                        // Fields that tidy their own value (name, PAN, salary)
                        // sync on blur: sent on every keystroke, the tidied value
                        // came back over letters still being typed.
                        TextInput::make('customer_name')
                            ->label('Customer Name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->extraInputAttributes(['style' => 'text-transform: capitalize'])
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('customer_name', Str::title($state));
                            }),

                        TextInput::make('mobile_no')
                            ->label('Mobile Number')
                            ->required()
                            ->tel()
                            ->live()
                            ->inputMode('numeric')
                            ->maxLength(10)
                            ->minLength(10)
                            ->afterStateUpdated(function ($state, callable $set, $livewire) {
                                // Keep only digits and limit to 10
                                $state = substr(preg_replace('/\D/', '', $state ?? ''), 0, 10);

                                $set('mobile_no', $state);

                                // Validate once 10 digits are entered
                                if (strlen($state) === 10) {
                                    $livewire->validateOnly('data.mobile_no');
                                }
                            })
                            ->rules([
                                'required',
                                'digits:10',
                                'regex:/^[6-9]\d{9}$/',
                            ])
                            ->placeholder('9876543210')
                            ->prefix('+91')
                            ->validationMessages([
                                'required' => 'Mobile number is required.',
                                'digits' => 'Mobile number must be exactly 10 digits.',
                                'regex' => 'Please enter a valid Indian mobile number starting with 6, 7, 8, or 9.',
                            ]),

                        Hidden::make('pan_status')
                            ->default('')
                            ->dehydrated(false),

                        // Checked against BOTH customers and open leads
                        // (PanDuplicateService). A clash blocks the save until
                        // an Admin approves a PAN request for this lead.
                        TextInput::make('pan_number')
                            ->label('PAN Number')
                            // ->required()
                            ->live(onBlur: true)
                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                            ->maxLength(10)
                            ->minLength(10)
                            ->afterStateUpdated(function (?string $state, Set $set, $livewire, ?Lead $record) {
                                $state = PanDuplicateService::normalize($state);

                                $set('pan_number', $state);

                                self::refreshPanStatus($set, $state, $record);

                                if (strlen((string) $state) === 10) {
                                    $livewire->validateOnly('data.pan_number');
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state): ?string => PanDuplicateService::normalize($state))

                            ->rules([
                                'nullable',
                                'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
                            ])
                            ->rule(
                                fn (?Lead $record): PanIsAvailable => new PanIsAvailable(ignoreLeadId: $record?->id),
                                fn (?Lead $record, Get $get): bool => self::panNeedsDuplicateCheck($record, $get('pan_number')),
                            )

                            ->placeholder('ABCDE1234F')
                            ->helperText(fn (Get $get): ?HtmlString => self::panStatusText($get('pan_status')))
                            ->validationMessages([
                                'regex' => 'Please enter a valid PAN number like ABCDE1234F.',
                            ]),

                        TextInput::make('email')
                            ->label('Email Address')
                            ->email()
                            ->maxLength(255)
                            ->unique(
                                table: 'leads',
                                column: 'email',
                                ignoreRecord: true,
                            )
                            ->placeholder('example@gmail.com')
                            ->validationMessages([
                                'email' => 'Please enter a valid email address.',
                                'unique' => 'This email already exists.',
                            ]),

                        Select::make('current_location')
                            ->label('Current Location')
                            // ->required()
                            ->searchable()
                            ->preload()
                            ->options(
                                fn () => City::query()
                                    ->where('is_active', 1)
                                    ->orderBy('city')
                                    ->get()
                                    ->mapWithKeys(fn ($item) => [
                                        $item->city => "{$item->city}, {$item->state}",
                                    ])
                            ),

                        Select::make('job_location')
                            ->label('Job Location')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->options(
                                fn () => City::query()
                                    ->where('is_active', 1)
                                    ->orderBy('city')
                                    ->get()
                                    ->mapWithKeys(fn ($item) => [
                                        $item->city => "{$item->city}, {$item->state}",
                                    ])
                            ),

                        Select::make('residence_location')
                            ->label('Residence Location')
                            // ->required()
                            ->searchable()
                            ->preload()
                            ->options(
                                fn () => City::query()
                                    ->where('is_active', 1)
                                    ->orderBy('city')
                                    ->get()
                                    ->mapWithKeys(fn ($item) => [
                                        $item->city => "{$item->city}, {$item->state}",
                                    ])
                            ),

                        TextInput::make('salary')
                            ->label('Salary')
                            ->prefix('₹')
                            ->amountInWords()
                            ->live(onBlur: true)
                            ->formatStateUsing(fn ($state) => filled($state)
                                ? indianCurrencyFormat($state)
                                : null)
                            ->afterStateUpdated(function ($state, callable $set) {
                                $value = preg_replace('/[^0-9]/', '', (string) $state);

                                if ($value !== '') {
                                    $set('salary', indianCurrencyFormat($value));
                                }
                            })
                            ->dehydrateStateUsing(function ($state) {
                                if (blank($state)) {
                                    return null;
                                }

                                return preg_replace('/[^0-9.]/', '', $state);
                            }),

                    ])->columns(2),

                Section::make('Duplicate PAN Found')
                    ->key('leadPanConflictSection')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('warning')
                    ->description(fn (Get $get): string => match ($get('pan_status')) {
                        'pending' => 'Your approval request is waiting for an Admin. The lead can be saved with this PAN once it is approved.',
                        'approved' => 'An Admin approved this PAN for you — you can save the lead.',
                        default => 'This PAN is already in the system. Correct it, or request Admin approval to use it on this lead.',
                    })
                    ->visible(fn (Get $get): bool => in_array($get('pan_status'), ['exists', 'pending', 'approved'], true))
                    ->headerActions([
                        Action::make('requestPanApproval')
                            ->label('Request Approval')
                            ->icon('heroicon-o-shield-check')
                            ->color('warning')
                            ->visible(fn (Get $get): bool => $get('pan_status') === 'exists' && self::currentEmployee() !== null)
                            ->modalHeading('Duplicate PAN Approval Request')
                            ->modalDescription('An Admin is notified. Once approved, you can save this lead with the PAN.')
                            ->modalSubmitActionLabel('Submit Request')
                            ->schema([
                                Textarea::make('reason')
                                    ->rows(3)
                                    ->required(),
                            ])
                            ->action(function (array $data, Get $get, Set $set, ?Lead $record): void {
                                self::requestPanApproval($data, $get, $set, $record);
                            }),
                    ])
                    ->schema([
                        Placeholder::make('pan_matches')
                            ->hiddenLabel()
                            ->content(fn (Get $get, ?Lead $record): HtmlString => self::panMatchesList($get('pan_number'), $record)),
                    ])
                    ->columnSpanFull(),

                Section::make('Initial Follow Up Details')
                    ->icon(Heroicon::OutlinedPhoneArrowUpRight)
                    ->description('This follow-up is recorded against today. The next follow-up date decides when the lead is due — changing it replaces the previous date and is kept in the lead\'s follow-up log.')
                    ->schema([

                        Select::make('follow_up_type')
                            ->options(['Call' => 'Call', 'WhatsApp' => 'WhatsApp', 'Email' => 'Email', 'Visit' => 'Visit'])
                            ->required(),

                        Select::make('status')
                            ->options(CustomerAssignment::FOLLOW_UP_STATUSES)
                            ->default('Pending')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, $set) {

                                // Clear bank if status is not eligible for other bank
                                if ($state !== 'Eligible for Other Bank') {
                                    $set('bank_id', null);
                                }

                                // Clear next follow-up date for closed statuses
                                if (in_array($state, CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES)) {
                                    $set('next_follow_up_date', null);
                                }
                            }),

                        Select::make('bank_id')
                            ->label('Bank Name')
                            ->options(
                                fn () => Bank::query()
                                    ->where('is_active', 1)
                                    ->orderBy('bank_name')
                                    ->pluck('bank_name', 'id')
                                    ->toArray()
                            )
                            ->searchable()
                            ->preload()
                            ->required(
                                fn ($get) => $get('status') === 'Eligible for Other Bank'
                            )
                            ->visible(
                                fn ($get) => $get('status') === 'Eligible for Other Bank'
                            )
                            ->live(),

                        // DatePicker::make('next_follow_up_date')
                        //     ->label('Next Follow Up Date')
                        //     ->displayFormat('d F Y')
                        //     ->native(false)
                        //     ->suffixIcon('heroicon-m-calendar')
                        //     ->minDate(now()->addDay())
                        //     ->required(),

                        // DatePicker::make('next_follow_up_date')
                        //     ->label('Next Follow Up Date')
                        //     ->displayFormat('d F Y')
                        //     ->native(false)
                        //     ->suffixIcon('heroicon-m-calendar')
                        //     ->minDate(now()->addDay())
                        //     ->required(
                        //         fn($get) => !in_array($get('status'), [
                        //             'Not Interested',
                        //             'Not Eligible',
                        //         ])
                        //     )
                        //     ->visible(
                        //         fn($get) => !in_array($get('status'), [
                        //             'Not Interested',
                        //             'Not Eligible',
                        //         ])
                        //     ),

                        // DateTimePicker::make('next_follow_up_date')
                        //     ->label('Next Follow Up Date & Time')
                        //     ->displayFormat('d F Y h:i A')
                        //     ->native(false)
                        //     ->seconds(false)
                        //     ->minDate(today())
                        //     ->required(
                        //         fn($get) => !in_array($get('status'), [
                        //             'Not Interested',
                        //             'Not Eligible',
                        //         ])
                        //     )
                        //     ->visible(
                        //         fn($get) => !in_array($get('status'), [
                        //             'Not Interested',
                        //             'Not Eligible',
                        //         ])
                        //     ),
                        // DateTimePicker::make('next_follow_up_date')
                        //     ->label('Next Follow Up Date & Time')
                        //     ->displayFormat('d F Y h:i A')
                        //     ->native(false)
                        //     ->seconds(false)
                        //     ->minDate(today())
                        //     ->required(
                        //         fn($get) => !in_array($get('status'), [
                        //             'Not Interested',
                        //             'Not Eligible',
                        //         ])
                        //     )
                        //     ->visible(
                        //         fn($get) => !in_array($get('status'), [
                        //             'Not Interested',
                        //             'Not Eligible',
                        //         ])
                        //     )
                        //     ->placeholder('Select date & time')
                        //     ->helperText('⚠️ Please select both date and time for the next follow-up.'),

                        // DateTimePicker::make('next_follow_up_date')
                        //     ->label('Next Follow Up Date & Time')
                        //     ->displayFormat('d F Y h:i A')
                        //     ->native(false)
                        //     ->seconds(false)
                        //     ->minDate(today())
                        //     ->required(
                        //         fn($get) => !in_array($get('status'), [
                        //             'Not Interested',
                        //             'Not Eligible',
                        //         ])
                        //     )
                        //     ->visible(
                        //         fn($get) => !in_array($get('status'), [
                        //             'Not Interested',
                        //             'Not Eligible',
                        //         ])
                        //     )
                        //     ->live()
                        //     ->placeholder('Select date & time')
                        //     ->helperText('⚠️ Please select both date and time for the next follow-up')
                        //     ->dehydrateStateUsing(fn($state) => $state),

                        // DateTimePicker::make('next_follow_up_date')
                        //     ->label('Next Follow Up Date & Time')
                        //     ->displayFormat('d F Y h:i A')
                        //     ->native(false)
                        //     ->closeOnDateSelection()
                        //     ->seconds(false)
                        //     ->minDate(today())
                        //     ->required(
                        //         fn($get) => !in_array($get('status'), [
                        //             'Not Interested',
                        //             'Not Eligible',
                        //         ])
                        //     )
                        //     ->visible(
                        //         fn($get) => !in_array($get('status'), [
                        //             'Not Interested',
                        //             'Not Eligible',
                        //         ])
                        //     )
                        //     ->placeholder('Select date & time')
                        //     ->helperText('⚠️ Please select both date and time for the next follow-up'),

                        Flatpickr::make('next_follow_up_date')
                            ->label('Next Follow Up Date & Time')
                            ->time(true)
                            ->time24hr(false)
                            ->seconds(false)
                            ->minuteIncrement(15)
                            ->format('Y-m-d H:i')
                            ->displayFormat('d M Y h:i K')
                            ->minDate(today())
                            ->required(
                                fn ($get) => ! in_array($get('status'), [
                                    'Not Interested',
                                    'Not Eligible',
                                ])
                            )
                            ->visible(
                                fn ($get) => ! in_array($get('status'), [
                                    'Not Interested',
                                    'Not Eligible',
                                ])
                            )
                            ->placeholder('Select date & time')
                            ->suffixIcon('heroicon-m-calendar')
                            ->helperText('Select date and time for the next follow-up.'),

                        Textarea::make('remarks')
                            ->rows(4)
                            ->required()
                            ->columnSpanFull(),

                        // The lead's owner. Normally the creator's own employee record,
                        // kept out of sight; a login without one (the Admin) picks who
                        // will work the lead instead of leaving it with nobody.
                        Select::make('employee_id')
                            ->label('Assign lead to')
                            ->options(fn (): array => self::ownerOptions())
                            ->default(fn () => auth()->user()?->employee?->id)
                            ->required(fn (?Lead $record): bool => self::asksForOwner($record))
                            ->visible(fn (?Lead $record): bool => self::asksForOwner($record))
                            ->dehydratedWhenHidden()
                            ->helperText('Your login has no employee record of its own, so choose who will work this lead.')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    /**
     * A new lead is always checked; an existing one only when its PAN is
     * being changed (older leads may already share a PAN, and editing a
     * follow-up must not trip over that), and never once an Admin has
     * approved this PAN for this lead.
     */
    public static function panNeedsDuplicateCheck(?Lead $record, ?string $pan): bool
    {
        $pan = PanDuplicateService::normalize($pan);

        if ($pan === null) {
            return false;
        }

        if ($record && PanDuplicateService::normalize($record->pan_number) === $pan) {
            return false;
        }

        return app(PanDuplicateService::class)
            ->approvedRequest(self::currentEmployee(), $pan, CustomerPanRequest::FOR_LEAD, $record?->id) === null;
    }

    /**
     * pan_status: '' (nothing to say), available, exists (clash, no request
     * yet), pending (request raised) or approved (save allowed).
     */
    public static function refreshPanStatus(Set $set, ?string $pan, ?Lead $record): void
    {
        if (! PanDuplicateService::isWellFormed($pan)
            || ($record && PanDuplicateService::normalize($record->pan_number) === PanDuplicateService::normalize($pan))) {
            $set('pan_status', '');

            return;
        }

        $service = app(PanDuplicateService::class);

        if (! $service->firstMatch($pan, ignoreLeadId: $record?->id)) {
            $set('pan_status', 'available');

            return;
        }

        $employee = self::currentEmployee();

        $set('pan_status', match (true) {
            $service->approvedRequest($employee, $pan, CustomerPanRequest::FOR_LEAD, $record?->id) !== null => 'approved',
            $service->pendingRequest($employee, $pan, CustomerPanRequest::FOR_LEAD, $record?->id) !== null => 'pending',
            default => 'exists',
        });
    }

    protected static function panStatusText(?string $status): ?HtmlString
    {
        return match ($status) {
            'available' => new HtmlString('<span class="text-sm font-bold text-success-600">✅ PAN Available</span>'),
            'exists' => new HtmlString('<span class="text-sm font-bold text-danger-600">⚠️ PAN already exists — see Duplicate PAN Found below</span>'),
            'pending' => new HtmlString('<span class="text-sm font-bold text-warning-600">⏳ Duplicate PAN — approval pending</span>'),
            'approved' => new HtmlString('<span class="text-sm font-bold text-success-600">✅ Duplicate PAN approved by Admin</span>'),
            default => null,
        };
    }

    protected static function panMatchesList(?string $pan, ?Lead $record): HtmlString
    {
        $items = app(PanDuplicateService::class)
            ->matches($pan, ignoreLeadId: $record?->id)
            ->map(fn (PanMatch $match): string => '<li><span class="font-semibold">'.e($match->typeLabel()).'</span> — '
                .e($match->name ?? '-')
                .' · '.e($match->mobile ?? '-')
                .' · Owner: '.e($match->ownerName ?? '-')
                .' · Status: '.e($match->status ?? '-').'</li>')
            ->implode('');

        return new HtmlString('<ul class="list-disc space-y-1 ps-5 text-sm">'.$items.'</ul>');
    }

    /**
     * @param  array{reason: string}  $data
     */
    protected static function requestPanApproval(array $data, Get $get, Set $set, ?Lead $record): void
    {
        $employee = self::currentEmployee();
        $service = app(PanDuplicateService::class);
        $pan = PanDuplicateService::normalize($get('pan_number'));
        $match = $service->firstMatch($pan, ignoreLeadId: $record?->id);

        if (! $employee || ! $match) {
            Notification::make()
                ->title($employee ? 'This PAN is no longer in use — you can save the lead.' : 'Only a login with an employee profile can request approval.')
                ->warning()
                ->send();

            self::refreshPanStatus($set, $pan, $record);

            return;
        }

        $service->raise($employee, $match, CustomerPanRequest::FOR_LEAD, ['reason' => $data['reason']], leadId: $record?->id);

        $set('pan_status', 'pending');

        Notification::make()
            ->title('Approval Request Submitted')
            ->body('Your request has been sent to Admin for approval.')
            ->success()
            ->send();
    }

    public static function notifyApprovalAlreadyUsed(): void
    {
        Notification::make()
            ->title('PAN Request Already Used')
            ->body('This PAN approval has already been used to save a lead.')
            ->warning()
            ->send();
    }

    protected static function currentEmployee(): ?Employee
    {
        return auth()->user()?->employee;
    }

    /**
     * Asked when the signed-in login has no employee record — on a new
     * lead, or on an existing one still without an owner.
     */
    public static function asksForOwner(?Lead $record): bool
    {
        if (auth()->user()?->employee !== null) {
            return false;
        }

        return $record === null || blank($record->employee_id);
    }

    /**
     * Current employees the login may hand a lead to (the Admin: everyone).
     *
     * @return array<int, string>
     */
    protected static function ownerOptions(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return Employee::query()
            ->whereIn('id', HierarchyService::visibleEmployeeIds($user))
            ->where('exit_status', '!=', 'yes')
            ->orderBy('emp_name')
            ->get(['id', 'emp_name', 'emp_id'])
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => EmployeeOptions::label($employee)])
            ->all();
    }
}
