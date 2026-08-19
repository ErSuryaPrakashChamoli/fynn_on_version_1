<?php

namespace App\Filament\Resources\Customers\Schemas;



use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Forms\Components\CheckboxList;
use App\Models\City;
use App\Models\Customer;
use App\Models\Employee;
use Illuminate\Support\Str;
use Illuminate\Support\HtmlString;
use App\Models\CustomerStageHistory;

use Filament\Forms\Components\Placeholder;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use App\Models\CustomerDocument;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Hidden;

use Filament\Forms\Components\Component;
use App\Models\Bank;
use Filament\Forms\Components\ViewField;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Set;
use App\Services\CustomerJourneyService;
use App\Models\CustomerPanRequest;
use Filament\Infolists\Components\ViewEntry;


class CustomerForm
{

    protected static function lockCallerFields(?Customer $record): bool
    {
        return $record &&
            auth()->user()->employee?->designation !== Employee::DESIGNATION_ADMIN;
    }

    protected static function lockDuplicatePan(?Customer $record, $livewire): bool
    {

        return self::lockCallerFields($record)
            || filled($livewire->existingCustomer);
    }

    protected static function lockAfterFilled(?Customer $record, string $field): bool
    {
        if (! $record) {
            return false; // Creating
        }

        return filled($record->{$field});
    }

    protected static function isApprovedPanRequest($livewire): bool
    {
        return $livewire instanceof \App\Filament\Resources\Customers\Pages\CreateCustomer
            && $livewire->isApprovedPanRequest;
    }

    protected static function showPanRequests($livewire): bool
    {
        return $livewire instanceof \App\Filament\Resources\Customers\Pages\CreateCustomer
            && $livewire->showPanRequests;
    }

    public static function configure(Schema $schema): Schema
    {
        $banks = [
            'BFL Prime' => 'BFL Prime',
            'BFL Growth' => 'BFL Growth',
            'BFL SOL' => 'BFL SOL',
            'BFL RSL' => 'BFL RSL',
            'ABFL' => 'ABFL',
            'Incred' => 'Incred',
            'Fibe' => 'Fibe',
            'Poonawala' => 'Poonawala',
            'Finnable' => 'Finnable',
            'Tata Capital' => 'Tata Capital',
            'Piramal Finance' => 'Piramal Finance',
            'HDFC Bank' => 'HDFC Bank',
            'ICICI Bank' => 'ICICI Bank',
            'Axis Bank' => 'Axis Bank',
            'State Bank of India' => 'State Bank of India',
            'Kotak Mahindra Bank' => 'Kotak Mahindra Bank',
            'IndusInd Bank' => 'IndusInd Bank',
            'Yes Bank' => 'Yes Bank',
            'Punjab National Bank' => 'Punjab National Bank',
            'Bank of Baroda' => 'Bank of Baroda',
            'Canara Bank' => 'Canara Bank',
            'IDFC First Bank' => 'IDFC First Bank',
            'AU Small Finance Bank' => 'AU Small Finance Bank',
            'LNT' => 'LNT'
            // 'Other' => 'Other',
        ];

        asort($banks);

        $currencyField = fn(string $name, string $label) => TextInput::make($name)
            ->label($label)
            ->prefix('₹')
            ->live()
            ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : null)
            ->afterStateUpdated(function ($state, Set $set) use ($name) {
                $value = preg_replace('/[^0-9]/', '', (string) $state);

                if ($value !== '') {
                    $set($name, indianCurrencyFormat($value));
                }
            })
            ->dehydrateStateUsing(fn($state) => preg_replace('/[^0-9]/', '', (string) $state))
            ->visible(fn(Get $get) => $get('disbursal_status') === 'disbursed');

        return $schema

            ->components([
                // Sticky Journey Tracker Widget
                View::make('filament.components.customer-journey-progress')
                    ->key('customerJourneyProgress')
                    ->columnSpanFull()
                    ->visibleOn('edit')
                    ->extraAttributes([
                        'class' => 'sticky z-50 self-start',
                        'style' => 'top: 5.5rem;',
                    ]),


                Section::make('Existing Customer')
                    // ->visible(function (Get $get, $livewire): bool {

                    //     if ($livewire->isApprovedPanRequest) {
                    //         return false;
                    //     }

                    //     return filled($get('existing_customer_id'));
                    // })
                    ->visible(function (Get $get, $livewire): bool {

                        if (self::isApprovedPanRequest($livewire)) {
                            return false;
                        }

                        return filled($get('existing_customer_id'));
                    })

                    ->headerActions([

                        Action::make('requestApproval')
                            ->label('Request Approval')
                            ->icon('heroicon-o-shield-check')
                            ->color('warning')
                            ->modalHeading('Duplicate PAN Approval Request')
                            ->modalSubmitActionLabel('Submit Request')
                            ->form([

                                Select::make('requested_bank_id')
                                    ->label('Requested Bank')
                                    ->options(Bank::pluck('bank_name', 'id'))
                                    ->searchable()
                                    ->required(),

                                Select::make('requested_loan_type')
                                    ->label('Loan Type')
                                    ->options([
                                        'personal_loan' => 'Personal Loan',
                                        'business_loan' => 'Business Loan',
                                        'home_loan' => 'Home Loan',
                                        'car_loan' => 'Car Loan',
                                        'education_loan' => 'Education Loan',
                                        'gold_loan' => 'Gold Loan',
                                        'lap' => 'Loan Against Property',
                                        'credit_card' => 'Credit Card',
                                        'overdraft' => 'Overdraft',
                                    ])
                                    ->required(),

                                Textarea::make('reason')
                                    ->rows(3)
                                    ->required(),
                            ])
                            ->action(function (array $data, $livewire, Get $get) {

                                $employee = auth()->user()->employee;

                                $bank = Bank::find($data['requested_bank_id']);

                                $customer = Customer::findOrFail(
                                    $get('existing_customer_id')
                                );

                                CustomerPanRequest::create([
                                    // 'customer_id' => $get('existing_customer_id'),
                                    'customer_id' => $customer->id,
                                    'pan_number' => $customer->pan_number,

                                    'requested_by' => $employee->id,
                                    'requested_by_emp_id' => $employee->emp_id,
                                    'requested_by_name' => $employee->emp_name,

                                    'team_leader_id' => $employee->superviser_id,
                                    'team_leader_name' => optional($employee->superviser)->emp_name,

                                    'manager_id' => $employee->manager_id,
                                    'manager_name' => optional($employee->manager)->emp_name,

                                    'cluster_manager_id' => $employee->cluster_id,
                                    'cluster_manager_name' => optional($employee->cluster)->emp_name,

                                    'requested_bank_id' => $data['requested_bank_id'],
                                    'requested_bank_name' => $bank?->bank_name,

                                    'requested_loan_type' => $data['requested_loan_type'],
                                    'reason' => $data['reason'],

                                    'status' => CustomerPanRequest::STATUS_PENDING,
                                ]);

                                $livewire->approvalRequested = true;

                                Notification::make()
                                    ->title('Approval Request Submitted')
                                    ->body('Your request has been sent to Admin for approval.')
                                    ->success()
                                    ->send();
                            }),

                        // VIEW / HIDE REQUESTS
                        Action::make('togglePanRequests')
                            ->label(
                                fn($livewire) =>
                                $livewire->showPanRequests
                                    ? 'Hide Requests'
                                    : 'View Requests'
                            )
                            ->icon(
                                fn($livewire) =>
                                $livewire->showPanRequests
                                    ? 'heroicon-o-chevron-up'
                                    : 'heroicon-o-chevron-down'
                            )
                            ->color('gray')
                            ->action(function ($livewire) {
                                $livewire->showPanRequests =
                                    ! $livewire->showPanRequests;
                            }),
                    ])

                    ->schema([

                        Placeholder::make('customer_name')
                            ->label('Customer Name')
                            ->content(
                                fn($livewire) =>
                                $livewire->existingCustomer?->customer_name
                            ),

                        Placeholder::make('mobile')
                            ->label('Mobile')
                            ->content(
                                fn($livewire) =>
                                $livewire->existingCustomer?->mobile_no
                            ),

                        Placeholder::make('owner')
                            ->label('Owner')
                            ->content(
                                fn($livewire) =>
                                $livewire->existingCustomer?->employee?->emp_name
                            ),

                        Placeholder::make('status')
                            ->label('Current Status')
                            ->content(
                                fn($livewire) =>
                                $livewire->existingCustomer?->journey_status
                            ),


                        ViewField::make('pan_request_history')
                            ->view('filament.resources.customers.pages.pan-request-history')
                            ->viewData(function (Get $get) {

                                $customerId = $get('existing_customer_id');

                                if (! $customerId) {
                                    return [
                                        'requests' => collect(),
                                    ];
                                }

                                $user = auth()->user();
                                $employee = $user?->employee;

                                if (! $employee) {
                                    return [
                                        'requests' => collect(),
                                    ];
                                }

                                $query = CustomerPanRequest::query()
                                    ->where('customer_id', $customerId)
                                    ->latest();

                                /*
                                |--------------------------------------------------------------------------
                                | ADMIN
                                |--------------------------------------------------------------------------
                                | Admin can see ALL requests for this customer.
                                |--------------------------------------------------------------------------
                                */

                                if ($user->hasRole('Admin')) {
                                    return [
                                        'requests' => $query->get(),
                                    ];
                                }

                                /*
                                |--------------------------------------------------------------------------
                                | CLUSTER MANAGER
                                |--------------------------------------------------------------------------
                                | See requests raised by employees belonging to this cluster.
                                |--------------------------------------------------------------------------
                                */

                                if ($employee->designation === Employee::DESIGNATION_CLUSTER) {

                                    $query->where('cluster_manager_id', $employee->id);
                                }

                                /*
                                |--------------------------------------------------------------------------
                                | MANAGER
                                |--------------------------------------------------------------------------
                                | See requests raised by employees under this manager.
                                |--------------------------------------------------------------------------
                                */ elseif ($employee->designation === Employee::DESIGNATION_MANAGER) {

                                    $query->where('manager_id', $employee->id);
                                }

                                /*
                                |--------------------------------------------------------------------------
                                | TEAM LEADER
                                |--------------------------------------------------------------------------
                                | See requests raised by callers under this team leader.
                                |--------------------------------------------------------------------------
                                */ elseif ($employee->designation === Employee::DESIGNATION_TEAM_LEADER) {

                                    $query->where('team_leader_id', $employee->id);
                                }

                                /*
                                |--------------------------------------------------------------------------
                                | CALLER
                                |--------------------------------------------------------------------------
                                | Caller can see only their own requests.
                                |--------------------------------------------------------------------------
                                */ elseif ($employee->designation === Employee::DESIGNATION_CALLER) {

                                    $query->where('requested_by', $employee->id);
                                }

                                /*
                                |--------------------------------------------------------------------------
                                | OTHER
                                |--------------------------------------------------------------------------
                                */ else {

                                    $query->where('requested_by', $employee->id);
                                }

                                return [
                                    'requests' => $query->get(),
                                ];
                            })
                            ->visible(fn($livewire) => self::showPanRequests($livewire))
                            ->columnSpanFull(),

                    ])
                    ->columns(3)
                    ->columnSpanFull(),
                // ->columns(2)
                //  ,

                // STAGE 0: Core Profile Details
                Section::make('Customer Basic Details')
                    // ->visible(
                    //     fn($livewire) =>
                    //     $livewire->panVerified
                    // )
                    ->schema([

                        Hidden::make('existing_customer_id')
                            ->dehydrated(false),

                        Hidden::make('pan_status')
                            ->default('')
                            ->dehydrated(false),

                        Hidden::make('pan_loading')
                            ->default(false)
                            ->dehydrated(false),

                        TextInput::make('pan_number')
                            ->label('PAN Number')
                            ->required()
                            ->live(onBlur: true)
                            ->maxLength(10)
                            ->minLength(10)

                            ->afterStateUpdated(function ($state, callable $set, $livewire) {

                                $set('pan_loading', true);
                                $set('pan_status', 'checking');

                                // usleep(800000);

                                $state = strtoupper($state);

                                $set('pan_number', $state);

                                if (strlen($state) !== 10) {
                                    $set('pan_loading', false);
                                    return;
                                }

                                // Validate PAN format
                                $livewire->validateOnly('data.pan_number');

                                // Search existing customer
                                $customer = Customer::where('pan_number', $state)->first();

                                if ($customer) {

                                    // Existing customer found
                                    $set('existing_customer_id', $customer->id);
                                    $set('pan_status', 'exists');

                                    $set('customer_name', $customer->customer_name);
                                    $set('mobile_no', $customer->mobile_no);
                                    $set('email', $customer->email);

                                    $livewire->existingCustomer = $customer;
                                    $livewire->panVerified = false;
                                } else {

                                    // PAN available
                                    $set('existing_customer_id', null);
                                    $set('pan_status', 'available');

                                    $livewire->existingCustomer = null;
                                    $livewire->panVerified = true;
                                }

                                $set('pan_loading', false);
                            })

                            ->dehydrateStateUsing(
                                fn($state) => strtoupper($state)
                            )

                            ->rules([
                                'required',
                                'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
                            ])

                            ->placeholder('ABCDE1234F')

                            // Important: disabled field must still be submitted
                            ->dehydrated(true)

                            ->disabled(
                                fn(?Customer $record, $livewire) =>
                                self::lockCallerFields($record)
                                    || $livewire->isApprovedPanRequest
                            )

                            ->suffixIcon('heroicon-m-arrow-path')

                            ->extraAttributes([
                                'wire:loading.class' => 'animate-spin',
                                'wire:target' => 'data.pan_number',
                            ])

                            ->helperText(function (Get $get) {

                                return match ($get('pan_status')) {

                                    'checking' => new HtmlString(
                                        '<span class="font-bold text-primary-600 text-sm">
                                        ⏳ Checking PAN...
                                    </span>'
                                    ),

                                    'available' => new HtmlString(
                                        '<span class="font-bold text-success-600 text-sm">
                                            ✅ PAN Available
                                        </span>'
                                    ),

                                    'exists' => new HtmlString(
                                        '<span class="font-bold text-danger-600 text-sm">
                                            ⚠️ Existing Customer Found
                                        </span>'
                                    ),

                                    default => null,
                                };
                            })

                            ->validationMessages([
                                'regex' => 'Please enter a valid PAN number like ABCDE1234F.',
                            ]),

                        TextInput::make('customer_name')
                            ->label('Customer Name')
                            ->required()
                            ->maxLength(255)
                            ->live()
                            ->disabled(
                                fn(Get $get, ?Customer $record) =>
                                self::lockCallerFields($record)
                                    || filled($get('existing_customer_id'))
                            )
                            ->dehydrated(true)
                            ->afterStateUpdated(
                                fn($state, callable $set) =>
                                $set('customer_name', Str::title($state))
                            ),

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
                                $state = substr(
                                    preg_replace('/\D/', '', $state ?? ''),
                                    0,
                                    10
                                );

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
                            ])

                            ->disabled(
                                fn(Get $get, ?Customer $record) =>
                                self::lockCallerFields($record)
                                    || filled($get('existing_customer_id'))
                            )

                            // Important: submit even when disabled
                            ->dehydrated(true),

                        TextInput::make('email')
                            ->label('Email Address')
                            ->email()
                            ->required()
                            ->maxLength(255)

                            ->disabled(function (?Customer $record, Get $get): bool {

                                if (filled($get('existing_customer_id'))) {
                                    return true;
                                }

                                if (! $record) {
                                    return false;
                                }

                                return in_array($record->journey_status, [
                                    'approved',
                                    'sanctioned',
                                    'disbursal',
                                    'finalized',
                                ]);
                            })

                            ->dehydrated(true),


                        // TextInput::make('email')
                        //     ->label('Email Address')
                        //     ->email()
                        //     ->required()
                        //     ->maxLength(255)
                        //     // ->unique(
                        //     //     table: 'customers',
                        //     //     column: 'email',
                        //     //     ignoreRecord: true,
                        //     // )

                        //     ->disabled(function (?Customer $record, Get $get): bool {

                        //         if (filled($get('existing_customer_id'))) {
                        //             return true;
                        //         }

                        //         if (! $record) {
                        //             return false; // Creating a customer
                        //         }

                        //         // Lock email from Approval stage onwards
                        //         return in_array($record->journey_status, [
                        //             'approved',
                        //             'sanctioned',
                        //             'disbursal',
                        //             'finalized',
                        //         ]);
                        //     }),






                        Select::make('job_location')
                            ->label('Job Location')
                            // ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(
                                fn(Get $get, ?Customer $record, $livewire) =>
                                self::lockCallerFields($record)
                                    || (
                                        filled($get('existing_customer_id'))
                                        && ! $livewire->isApprovedPanRequest
                                    )
                            )

                            ->options(fn() => City::query()->where('is_active', 1)->orderBy('city')->get()->pluck('city', 'city')),

                        Select::make('residence_location')
                            ->label('Residence Location')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(
                                fn(Get $get, ?Customer $record, $livewire) =>
                                self::lockCallerFields($record)
                                    || (
                                        filled($get('existing_customer_id'))
                                        && ! $livewire->isApprovedPanRequest
                                    )
                            )

                            ->options(fn() => City::query()->where('is_active', 1)->orderBy('city')->get()->mapWithKeys(fn($item) => [$item->city => "{$item->city}, {$item->state}"])),

                        TextInput::make('salary')
                            ->label('Salary')
                            ->prefix('₹')
                            ->live()
                            // ->required()
                            ->required(
                                fn(Get $get) =>
                                $get('eligibility_status') === 'eligible'
                            )
                            ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : null)
                            ->disabled(
                                fn(Get $get, ?Customer $record, $livewire) =>
                                self::lockCallerFields($record)
                                    || (
                                        filled($get('existing_customer_id'))
                                        && ! $livewire->isApprovedPanRequest
                                    )
                            )

                            ->afterStateUpdated(function ($state, callable $set) {
                                $value = preg_replace('/[^0-9]/', '', (string) $state);
                                if ($value !== '') {
                                    $set('salary', indianCurrencyFormat($value));
                                }
                            })
                            ->dehydrateStateUsing(fn($state) => preg_replace('/[^0-9]/', '', (string) $state)),

                        Select::make('current_location')
                            ->label('Current Location')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(
                                fn(Get $get, ?Customer $record, $livewire) =>
                                self::lockCallerFields($record)
                                    || (
                                        filled($get('existing_customer_id'))
                                        && ! $livewire->isApprovedPanRequest
                                    )
                            )

                            ->options(fn() => City::query()->where('is_active', 1)->orderBy('city')->get()->mapWithKeys(fn($item) => [$item->city => "{$item->city}, {$item->state}"])),

                        Select::make('eligibility_status')
                            ->label('Eligibility')
                            ->required()
                            ->options([
                                'eligible' => 'Eligible',
                                'not_eligible' => 'Not Eligible',
                                'consent_pending' => 'Consent Pending',
                            ])
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {

                                switch ($state) {

                                    case 'eligible':
                                        $set('journey_status', 'sfl');
                                        break;

                                    case 'not_eligible':
                                    case 'consent_pending':
                                        $set('journey_status', 'not_started');
                                        break;
                                }
                            })
                            ->disabled(function (Get $get, ?Customer $record, string $operation, $livewire): bool {

                                return (
                                    filled($get('existing_customer_id'))
                                    && ! $livewire->isApprovedPanRequest
                                )
                                    || (
                                        $operation === 'edit'
                                        && in_array($record?->eligibility_status, [
                                            'not_eligible',
                                            'consent_pending',
                                        ])
                                    )
                                    || self::lockCallerFields($record)
                                    || (
                                        $operation === 'edit'
                                        && ! auth()->user()->hasAnyRole(['Admin', 'Manager'])
                                    );
                            }),


                        // Select::make('assign_to')
                        //     ->label('Assign To')
                        //     ->relationship('assignedTo', 'emp_name')
                        //     ->searchable()
                        //     ->required()
                        //     ->disabled(
                        //         fn(Get $get, ?Customer $record, $livewire): bool =>
                        //         self::lockCallerFields($record)
                        //             || (
                        //                 filled($get('existing_customer_id'))
                        //                 && ! $livewire->isApprovedPanRequest
                        //             )
                        //     )
                        //     ->default(fn() => auth()->user()->employee?->id)
                        //     ->preload()
                        //     ->nullable(),

                        // Select::make('assign_to')
                        //     ->label('Assign To')
                        //     ->relationship('assignedTo', 'emp_name')
                        //     ->searchable()
                        //     ->required()
                        //     ->default(fn() => auth()->user()->employee?->id)
                        //     ->disabled(function (): bool {
                        //         $designation = auth()->user()->employee?->designation;

                        //         return in_array($designation, [
                        //             Employee::DESIGNATION_MANAGER,
                        //             Employee::DESIGNATION_TEAM_LEADER,
                        //         ], true);
                        //     })
                        //     ->dehydrated(true)
                        //     ->preload()
                        //     ->nullable(),

                        Select::make('assign_to')
                            ->label('Assign To')
                            ->relationship('assignedTo', 'emp_name')
                            ->searchable()
                            ->required()
                            ->disabled(
                                fn(Get $get, ?Customer $record, $livewire): bool =>
                                self::lockCallerFields($record)
                                    || (
                                        filled($get('existing_customer_id'))
                                        && ! $livewire->isApprovedPanRequest
                                    )
                                    || (
                                        $livewire instanceof \App\Filament\Resources\Customers\Pages\CreateCustomer
                                        && $livewire->isDirectCustomer
                                    )
                            )
                            ->default(fn() => auth()->user()->employee?->id)
                            ->dehydrated(true)
                            ->preload()
                            ->nullable(),


                        Select::make('eligibility_reason')
                            ->label('Not Eligible Reason')
                            ->options([
                                'company_not_listed' => 'Company Not Listed',
                                'cibil_score' => 'CIBIL Score',
                                'defaulter_bounces' => 'Defaulter / Bounces',
                                'no_residence_proof' => 'No Residence Proof',
                                'low_salary' => 'Low Salary',
                                'location_issue' => 'Location',
                            ])
                            ->disabled(
                                fn(Get $get, ?Customer $record) =>
                                self::lockCallerFields($record)
                                    || filled($get('existing_customer_id'))
                            )
                            ->visible(fn(Get $get): bool => $get('eligibility_status') === 'not_eligible')
                            ->required(fn(Get $get): bool => $get('eligibility_status') === 'not_eligible'),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->disabled(
                        fn(string $operation): bool =>
                        $operation === 'edit'
                            && auth()->user()->hasRole('Employee')
                    ),

                // PIPELINE AREA: Dynamic Sequential Sections Layout Container
                Section::make('Application Progress Steps')
                    ->visible(fn() => ! auth()->user()->hasRole('Caller'))
                    ->schema([

                        Placeholder::make('stage_history_timeline')
                            ->label('📋 Pipeline Audit Trail & Activity Logs')
                            ->columnSpanFull()
                            ->live()
                            ->content(function ($record, Get $get) {
                                $trackState = $get('journey_status');
                                $trackUnderwriting = $get('underwriting_status'); // Dono states ko bind kiya

                                if (! $record) {
                                    return new HtmlString('<p class="text-gray-400 text-sm">New registration—No history recorded yet.</p>');
                                }

                                // 'with('user')' se real-time user name fetch query crash nahi karegi
                                $activities = CustomerStageHistory::where('customer_id', $record->id)
                                    ->with('user')
                                    ->latest()
                                    ->get();

                                if ($activities->isEmpty()) {
                                    return new HtmlString('<p class="text-gray-400 text-sm">No state transitions caught yet.</p>');
                                }

                                $html = '<div class="space-y-3 mt-2 border-l-2 border-primary-500 pl-4">';
                                foreach ($activities as $log) {
                                    $html .= sprintf(
                                        '
                                    <div class="text-sm">
                                        <span class="font-semibold text-primary-600 dark:text-primary-400">%s</span>
                                        <span class="text-gray-500">changed to</span>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200">%s</span>
                                        <div class="text-xs text-gray-400 font-mono mt-0.5">%s by %s</div>
                                    </div>',
                                        e($log->stage_name),
                                        e(\Illuminate\Support\Str::headline($log->status_value)),
                                        e($log->created_at->format('d-M-Y h:i A')),
                                        e($log->user?->name ?? 'System')
                                    );
                                }
                                $html .= '</div>';

                                return new HtmlString($html);
                            }),

                        // STAGE 1: Journey Requirements (Always Visible for Admin/Manager)
                        Section::make('Journey Configuration')
                            ->visible(
                                fn() =>
                                auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER
                            )
                            ->schema([

                                TextInput::make('company_category')
                                    ->label('Company Name')
                                    ->maxLength(255)
                                    ->live()
                                    ->required(
                                        fn(Get $get) =>
                                        auth()->user()->hasAnyRole([
                                            'Admin',
                                            'Manager',
                                            'Team Leader',
                                            'Cluster Manager',
                                        ]) && $get('eligibility_status') === 'eligible'
                                    )
                                    ->afterStateUpdated(fn($state, callable $set) => $set('company_category', Str::title($state)))
                                    ->disabled(fn(?Customer $record) => self::lockAfterFilled($record, 'company_category')),



                                Select::make('loan_applied')
                                    ->label('Loan Type')
                                    ->options([
                                        'personal_loan' => 'Personal Loan',
                                        'business_loan' => 'Business Loan',
                                        'home_loan' => 'Home Loan',
                                        'car_loan' => 'Car Loan',
                                        'education_loan' => 'Education Loan',
                                        'gold_loan' => 'Gold Loan',
                                        'lap' => 'Loan Against Property',
                                        'credit_card' => 'Credit Card',
                                        'overdraft' => 'Overdraft',
                                        'other' => 'Other',
                                    ])
                                    ->searchable()
                                    ->preload()
                                    ->required(
                                        fn(Get $get) =>
                                        auth()->user()->hasAnyRole([
                                            'Admin',
                                            'Manager',
                                            'Team Leader',
                                            'Cluster Manager',
                                        ])
                                            && $get('eligibility_status') === 'eligible'
                                    )
                                    ->live()

                                    // Existing normal lock + approved PAN request lock
                                    ->disabled(
                                        fn(Get $get, ?Customer $record, $livewire): bool =>
                                        self::lockAfterFilled($record, 'loan_applied')
                                            || self::isApprovedPanRequest($livewire)
                                    )

                                    // Important: submit the value even though it is disabled
                                    ->dehydrated(true),

                                TextInput::make('other_loan_applied')
                                    ->label('Other Loan Type')
                                    ->visible(fn(Get $get): bool => $get('loan_applied') === 'other')
                                    // ->required(fn(Get $get): bool => $get('loan_applied') === 'other')
                                    ->required(fn(Get $get) =>
                                    auth()->user()->hasAnyRole([
                                        'Admin',
                                        'Manager',
                                        'Team Leader'
                                    ])
                                        && $get('loan_applied') === 'other')
                                    ->maxLength(255),

                                Select::make('bank_eligible_for')
                                    ->label('Bank Eligible For')
                                    ->options($banks)
                                    ->searchable()
                                    ->preload()
                                    ->required(
                                        fn(Get $get) =>
                                        auth()->user()->hasAnyRole([
                                            'Admin',
                                            'Manager',
                                            'Team Leader',
                                            'Cluster Manager',
                                        ]) && $get('eligibility_status') === 'eligible'
                                    )
                                    ->live()
                                    ->disabled(fn(?Customer $record) => self::lockAfterFilled($record, 'bank_eligible_for')),

                                TextInput::make('other_bank_eligible_for')
                                    ->label('Other Bank Name')
                                    ->maxLength(255)
                                    ->visible(fn(Get $get): bool => $get('bank_eligible_for') === 'Other')
                                    ->required(fn(Get $get): bool => $get('bank_eligible_for') === 'Other')
                                    ->required(fn(Get $get) =>
                                    auth()->user()->hasAnyRole(['Admin', 'Manager', 'Team Leader'])
                                        && $get('bank_eligible_for') === 'other'),

                                // Displaying Status as a clean read-only text input instead of manual choice dropdown
                                TextInput::make('journey_status')
                                    ->label('Application Stage')
                                    ->default('sfl')
                                    ->disabled()
                                    ->dehydrated()
                                    ->extraAttributes(['class' => 'font-bold text-primary-600']),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),





                        // ---------------- PROGRESSIVE STEP 1: SFL SECTION ----------------
                        Section::make('Step 1: SFL (Source File Logging)')
                            ->schema([


                                TextInput::make('application_no')
                                    ->label('Application No')
                                    ->maxLength(255)
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['sfl', 'underwriting', 'approved', 'sanctioned', 'not_approved', 'dropped', 'carry_forward']))
                                    ->dehydrated(),

                                TextInput::make('lan_no')
                                    ->label('Loan Account Number')
                                    ->maxLength(255)
                                    // ->required()

                                    // Fix: Agli stages me yeh field non-editable ho jaye par data visible rahe
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['underwriting', 'approved', 'sanctioned', 'not_approved', 'dropped', 'carry_forward']))
                                    ->dehydrated(),



                                TextInput::make('eligible_loan_amount')
                                    ->label('Eligible Loan Amount')
                                    ->prefix('₹')
                                    ->live()
                                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : null)
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $value = preg_replace('/[^0-9]/', '', (string) $state);

                                        if ($value !== '') {
                                            $set('eligible_loan_amount', indianCurrencyFormat($value));
                                        }
                                    })
                                    ->required(fn(Get $get) => $get('eligibility_status') === 'eligible')
                                    // ->dehydrateStateUsing(fn ($state) => preg_replace('/[^0-9]/', '', (string) $state))
                                    ->dehydrateStateUsing(function ($state) {
                                        $value = preg_replace('/[^0-9]/', '', (string) $state);

                                        return $value !== '' ? $value : null;
                                    })
                                    ->disabled(
                                        fn(Get $get): bool =>
                                        $get('eligibility_status') !== 'eligible'
                                            || in_array(
                                                strtolower((string) $get('journey_status')),
                                                [
                                                    'underwriting',
                                                    'approved',
                                                    'sanctioned',
                                                    'not_approved',
                                                    'dropped',
                                                    'carry_forward',
                                                ]
                                            )
                                    )
                                    ->dehydrated(),

                                Select::make('documentation_status')
                                    ->label('Documentation Status')
                                    ->options([
                                        'pending' => 'Pending',
                                        'complete' => 'Complete',
                                    ])
                                    ->live()
                                    // ->required()
                                    ->required(fn(Get $get) => $get('eligibility_status') === 'eligible')
                                    // Fix: Underwriting ya uske aage selection freeze ho jaye
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['underwriting', 'approved', 'sanctioned', 'not_approved', 'dropped', 'carry_forward']))
                                    ->dehydrated(),

                                CheckboxList::make('pending_document')
                                    ->label('Pending Documents Checklist')
                                    ->options([
                                        'aadhaar_card'            => 'AADHAR Card',
                                        'current_address_proof'   => 'Current Address Proof',
                                        'electricity_bill'        => 'Electricity Bill',
                                        'bank_statement'          => 'Bank Statement',
                                        'form_26as'               => 'Form 26AS',
                                        'photo'                   => 'Photo',
                                        'payslip'                 => 'Payslip',
                                        'soa_repayment_schedule'  => 'SOA / Repayment Schedule',
                                        'other'                   => 'Other',
                                    ])
                                    ->columns(2)
                                    ->bulkToggleable()
                                    ->searchable()
                                    ->visible(fn(Get $get): bool => strtolower((string) $get('documentation_status')) === 'pending')
                                    ->required(fn(Get $get): bool => strtolower((string) $get('documentation_status')) === 'pending')
                                    // Fix: Lock checkbox list when moved ahead
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['underwriting', 'approved', 'sanctioned', 'not_approved', 'dropped', 'carry_forward']))
                                    ->dehydrated(),


                                Textarea::make('sfl_remarks')
                                    ->label('SFL Remarks')
                                    ->rows(2)
                                    ->columnSpanFull()
                                    // Fix: Underwriting ya uske aage remarks non-editable ho jaye
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['underwriting', 'approved', 'sanctioned', 'not_approved', 'dropped', 'carry_forward']))
                                    ->dehydrated(),

                                Hidden::make('underwriting_status')
                                    ->default(null)
                                    ->dehydrated(true),

                                Placeholder::make('sfl_promotion_trigger')
                                    ->label('')
                                    ->visible(function (Get $get): bool {
                                        $journeyStatus = $get('journey_status');
                                        return filled($journeyStatus)
                                            && strtolower((string) $journeyStatus) === 'sfl' //not_started
                                            && strtolower((string) $get('documentation_status')) === 'complete';
                                    })
                                    ->hintAction(

                                        FormAction::make('promote_to_underwriting')
                                            ->label('Verify & Move to Underwriting')
                                            ->icon('heroicon-m-arrow-right-circle')
                                            ->color('success')

                                            ->requiresConfirmation()

                                            ->modalHeading('Move to Underwriting?')
                                            ->modalDescription('Are you sure you want to move this customer to the Underwriting stage? Once moved, the SFL section will become read-only.')
                                            ->modalSubmitActionLabel('Yes, Proceed')
                                            ->modalCancelActionLabel('Cancel')

                                            ->action(function (?Customer $record, callable $set) {

                                                if (! $record) {
                                                    return;
                                                }

                                                $record = CustomerJourneyService::moveToUnderwriting($record);

                                                $set('journey_status', $record->journey_status);
                                                $set('underwriting_status', $record->underwriting_status);

                                                Notification::make()
                                                    ->title('Stage will be changed after you click Save Changes.')
                                                    ->success()
                                                    ->send();
                                            })



                                    ),


                            ])
                            ->columns(2)
                            ->visible(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['underwriting', 'approved', 'sanctioned', 'sfl', 'not_approved', 'dropped', 'carry_forward'])),





                        // ---------------- PROGRESSIVE STEP 2: UNDERWRITING SECTION ----------------

                        Section::make('Step 2: Underwriting Analysis')
                            ->disabled(
                                fn(Get $get): bool =>
                                in_array(
                                    strtolower((string) $get('journey_status')),
                                    ['approved', 'sanctioned', 'not_approved', 'dropped', 'carry_forward']
                                )
                            )
                            ->schema([
                                Select::make('underwriting_status')
                                    ->label('Underwriting Status Decision')
                                    ->options([
                                        'in_process' => 'In Process',
                                        'approved' => 'Approved',
                                        'rejected' => 'Rejected',
                                    ])
                                    ->live()
                                    ->required(function (Get $get) {
                                        return auth()->user()->hasAnyRole(['Admin', 'Team Leader', 'Manager', 'Cluster Manager'])
                                            && $get('documentation_status') === 'complete';
                                    }),
                                // ->required(fn(Get $get) => $get('documentation_status') === 'complete'),


                                DatePicker::make('approval_date')
                                    ->displayFormat('d F Y')
                                    ->maxDate(now())
                                    ->native(false)
                                    ->suffixIcon('heroicon-m-calendar')
                                    ->visible(fn(Get $get) => $get('underwriting_status') === 'approved')
                                    ->required(fn(Get $get) => $get('underwriting_status') === 'approved')
                                    ->dehydrated(true)
                                    ->label('Approval Date'),


                                Textarea::make('underwriting_remarks')
                                    ->label('Underwriting Remarks')
                                    ->rows(2)
                                    ->columnSpanFull()
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['approved', 'sanctioned', 'not_approved', 'dropped', 'carry_forward']))
                                    ->dehydrated(),


                            ])
                            ->columns(2)
                            ->visible(function (Get $get): bool {
                                $journeyStatus = strtolower((string) $get('journey_status'));

                                return ! auth()->user()->hasRole('Caller')
                                    && (
                                        in_array($journeyStatus, [
                                            'underwriting',
                                            'approved',
                                            'sanctioned',
                                            'not_approved',
                                            'dropped',
                                            'carry_forward',
                                        ])
                                        || (
                                            $journeyStatus === 'sfl'
                                            && filled($get('underwriting_status'))
                                        )
                                    );
                            }),



                        // ---------------- PROGRESSIVE STEP 3: APPROVAL SECTION ----------------
                        Section::make('Step 3: Credit Approval Information')
                            ->schema([
                                TextInput::make('approved_loan_amount')
                                    ->label('Approved Sanctioned Amount')
                                    ->prefix('₹')
                                    ->live()
                                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : null)
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['approved', 'sanctioned', 'not_approved', 'dropped', 'carry_forward']))
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $value = preg_replace('/[^0-9]/', '', (string) $state);
                                        if ($value !== '') {
                                            $set('approved_loan_amount', indianCurrencyFormat($value));
                                        }
                                    })
                                    ->dehydrateStateUsing(fn($state) => preg_replace('/[^0-9]/', '', (string) $state))
                                    ->dehydrated(true)
                                    ->required(),



                                Select::make('sanctioned_bank')
                                    ->label('Final Sanctioned Issuing Bank')
                                    ->options(array_merge($banks, [
                                        'other' => 'Other',
                                    ]))
                                    ->searchable()
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['approved', 'sanctioned', 'not_approved', 'dropped', 'carry_forward']))
                                    ->dehydrated(true)
                                    ->live(),

                                Hidden::make('credit_approval_completed')
                                    ->dehydrated(true),

                                TextInput::make('other_sanctioned_bank')
                                    ->label('Enter Bank Name')
                                    ->visible(fn($get) => $get('sanctioned_bank') === 'other')
                                    ->required(fn($get) => $get('sanctioned_bank') === 'other')
                                    ->live()
                                    ->dehydrated(true)
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['approved', 'sanctioned', 'not_approved', 'dropped', 'carry_forward']))
                                    ->afterStateUpdated(fn($state, callable $set) => $set('other_sanctioned_bank', Str::title($state)))
                                    ->maxLength(255),



                                Textarea::make('approved_remarks')
                                    ->label('Approved Credit Remarks')
                                    ->rows(2)
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['approved', 'sanctioned', 'not_approved', 'dropped', 'carry_forward']))
                                    ->dehydrated(true)
                                    ->columnSpanFull(),

                                Placeholder::make('underwriting_actions')
                                    ->label('')
                                    ->visible(fn(Get $get): bool => strtolower((string) $get('journey_status')) === 'underwriting')
                                    ->hintActions([
                                        FormAction::make('promote_to_approval')
                                            ->label('Approve & Move to Credit Approval')
                                            ->visible(
                                                fn(Get $get) =>
                                                $get('underwriting_status') === 'approved'
                                            )
                                            ->icon('heroicon-m-check-badge')
                                            ->color('success')
                                            ->requiresConfirmation()
                                            // FIX 2: Added $set utility layer
                                            ->action(function (?\Illuminate\Database\Eloquent\Model $record, callable $set, Get $get) {


                                                if (! $record) {
                                                    return;
                                                }

                                                $data = [
                                                    'approved_loan_amount'   => $get('approved_loan_amount'),
                                                    'sanctioned_bank'        => $get('sanctioned_bank'),
                                                    'other_sanctioned_bank'  => $get('other_sanctioned_bank'),
                                                    'approved_remarks'       => $get('approved_remarks'),
                                                    'approval_date'         => $get('approval_date'),
                                                    'underwriting_remarks'   => $get('underwriting_remarks')
                                                ];

                                                $record = CustomerJourneyService::approve($record, $data);
                                                $set('journey_status', $record->journey_status);

                                                // $set('journey_status', $record->journey_status);
                                                // $set('underwriting_status', $record->underwriting_status);

                                                Notification::make()
                                                    ->success()
                                                    ->title('Click "Save Changes" to complete this stage.')
                                                    ->send();
                                            }),
                                    ]),
                            ])
                            ->columns(2)
                            ->visible(function (Get $get): bool {

                                return auth()->user()->hasAnyRole(['Admin', 'Team Leader', 'Manager', 'Cluster Manager'])
                                    && (
                                        in_array(
                                            strtolower((string) $get('journey_status')),
                                            ['approved', 'sanctioned', 'dropped', 'carry_forward']
                                        )
                                        || (
                                            strtolower((string) $get('journey_status')) === 'underwriting'
                                            && strtolower((string) $get('underwriting_status')) === 'approved'
                                        )
                                    );
                            })
                            ->disabled(
                                fn(Get $get): bool =>
                                strtolower((string) $get('journey_status')) === 'sanctioned'
                            )
                            ->dehydrated(),



                        // ---------------- PROGRESSIVE STEP 4: DISBURSED SECTION ----------------
                        Section::make('Step 4: Disbursal Payouts & Close')
                            // ->disabled(fn(Get $get) => (bool) $get('disbursal_finalized'))

                            ->schema([

                                Select::make('disbursal_status')
                                    ->label('Disbursal Status')
                                    ->options([
                                        'disbursed' => 'Disbursed',
                                        'carry_forward' => 'Carry Forward',
                                        'dropped' => 'Dropped',
                                        'on_hold' => 'On Hold',
                                    ])
                                    ->live()
                                    ->dehydrated(true)
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['sanctioned', 'not_approved', 'dropped']))
                                    ->required(),

                                Select::make('channel')
                                    ->label('Channel Name')
                                    ->options([
                                        'finance_buddha' => 'Finance Buddha',
                                        'profin_care' => 'Profin Care',
                                        'rare_crome' => 'Rare Crome',
                                        'ruloans' => 'Ruloans',
                                        'fast_credit' => 'Fast Credit',
                                        'kms_finbud' => 'KMS Finbud',
                                    ])
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['sanctioned', 'not_approved', 'dropped']))
                                    ->dehydrated(true)
                                    ->visible(
                                        fn(Get $get) =>
                                        in_array($get('disbursal_status'), [
                                            'disbursed',
                                            // 'carry_forward',
                                            'dropped',
                                        ])
                                    ),
                                // ->visible(fn(Get $get) => $get('disbursal_status') === 'disbursed'),

                                TextInput::make('sanctioned_loan_amount')
                                    ->label('Final Net Disbursed Loan Amount')
                                    ->prefix('₹')
                                    ->live()
                                    ->dehydrated(true)
                                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : null)
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $value = preg_replace('/[^0-9]/', '', (string) $state);

                                        if ($value !== '') {
                                            $set('sanctioned_loan_amount', indianCurrencyFormat($value));
                                        }
                                    })
                                    ->dehydrateStateUsing(fn($state) => preg_replace('/[^0-9]/', '', (string) $state))
                                    ->visible(fn(Get $get) => $get('disbursal_status') === 'disbursed')
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['sanctioned', 'not_approved', 'dropped']))
                                    ->required(fn(Get $get) => $get('disbursal_status') === 'disbursed'),

                                TextInput::make('cashback')
                                    ->label('Cashback Given')
                                    ->prefix('₹')
                                    ->live()
                                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : null)
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $value = preg_replace('/[^0-9]/', '', (string) $state);

                                        if ($value !== '') {
                                            $set('cashback', indianCurrencyFormat($value));
                                        }
                                    })
                                    ->dehydrateStateUsing(fn($state) => preg_replace('/[^0-9]/', '', (string) $state))
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['sanctioned', 'not_approved', 'dropped']))
                                    ->visible(fn(Get $get) => $get('disbursal_status') === 'disbursed'),

                                TextInput::make('subvention')
                                    ->label('Subvention Fees')
                                    ->prefix('₹')
                                    ->live()
                                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : null)
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $value = preg_replace('/[^0-9]/', '', (string) $state);

                                        if ($value !== '') {
                                            $set('subvention', indianCurrencyFormat($value));
                                        }
                                    })
                                    ->dehydrateStateUsing(fn($state) => preg_replace('/[^0-9]/', '', (string) $state))
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['sanctioned', 'not_approved', 'dropped']))
                                    ->visible(fn(Get $get) => $get('disbursal_status') === 'disbursed'),

                                TextInput::make('docking')
                                    ->label('Docking Charges')
                                    ->prefix('₹')
                                    ->live()
                                    ->formatStateUsing(fn($state) => filled($state) ? indianCurrencyFormat($state) : null)
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['sanctioned', 'not_approved', 'dropped']))
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $value = preg_replace('/[^0-9]/', '', (string) $state);

                                        if ($value !== '') {
                                            $set('docking', indianCurrencyFormat($value));
                                        }
                                    })
                                    ->dehydrateStateUsing(fn($state) => preg_replace('/[^0-9]/', '', (string) $state))
                                    ->visible(
                                        fn(Get $get) =>
                                        $get('disbursal_status') === 'disbursed'
                                            && auth()->user()?->hasRole('Admin')
                                    ),


                                DatePicker::make('carry_forward_date')
                                    ->label('Carry Forward Date')
                                    ->displayFormat('d F Y')
                                    ->native(false)
                                    ->dehydrated(true)
                                    ->suffixIcon('heroicon-m-calendar')
                                    ->minDate(today()) // Today and future dates only
                                    ->disabled(fn(Get $get): bool => in_array(
                                        strtolower((string) $get('journey_status')),
                                        ['sanctioned', 'not_approved', 'dropped', 'carry_forward']
                                    ))
                                    ->visible(fn(Get $get) => $get('disbursal_status') === 'carry_forward')
                                    ->required(fn(Get $get) => $get('disbursal_status') === 'carry_forward'),

                                Textarea::make('sanctioned_remarks')
                                    ->label('Final Disbursal Remarks')
                                    ->rows(2)
                                    ->disabled(fn(Get $get): bool => in_array(strtolower((string) $get('journey_status')), ['sanctioned', 'not_approved', 'dropped']))
                                    ->columnSpanFull()
                                    ->required(),

                                Hidden::make('disbursal_finalized')
                                    ->dehydrated(true),

                                Placeholder::make('disbursal_actions')
                                    ->label('')
                                    ->visible(
                                        fn(Get $get): bool =>
                                        ! $get('disbursal_finalized')
                                            && $get('disbursal_status') === 'disbursed'
                                            && (
                                                auth()->user()->hasRole('Admin')
                                                || auth()->user()->hasRole('Manager')
                                            )
                                    )
                                    ->hintAction(

                                        FormAction::make('finalize_disbursal')
                                            ->label('Finalize Disbursal')
                                            ->icon('heroicon-m-check-circle')
                                            ->color('success')
                                            ->requiresConfirmation()
                                            ->action(function (?\Illuminate\Database\Eloquent\Model $record, callable $set,  Get $get) {


                                                if (! $record) {
                                                    return;
                                                }

                                                $data = [
                                                    'disbursal_status'      => $get('disbursal_status'),
                                                    'channel'               => $get('channel'),
                                                    'sanctioned_loan_amount' => $get('sanctioned_loan_amount'),
                                                    'cashback'              => $get('cashback'),
                                                    'subvention'            => $get('subvention'),
                                                    'docking'               => $get('docking'),
                                                    'carry_forward_date'    => $get('carry_forward_date'),
                                                    'sanctioned_remarks'    => $get('sanctioned_remarks'),
                                                ];


                                                $record = CustomerJourneyService::sanction($record, $data);
                                                // $record = CustomerJourneyService::finalize($record,$data);
                                                $set('journey_status', $record->journey_status);
                                                $set('disbursal_finalized', true);


                                                Notification::make()
                                                    ->success()
                                                    ->title('Disbursal status updated successfully.')
                                                    ->send();

                                                //    $set('disbursal_finalized', true);

                                                return redirect()->to(
                                                    \App\Filament\Resources\Customers\CustomerResource::getUrl('index')
                                                );
                                            })
                                    ),
                            ])

                            ->columns(2)
                            ->visible(
                                fn(Get $get) => (
                                    auth()->user()->hasRole('Admin')
                                    || auth()->user()->hasRole('Manager')
                                )
                                    && (
                                        ($get('credit_approval_completed') ?? false)
                                        || in_array(
                                            strtolower((string) $get('journey_status')),
                                            [
                                                'sanctioned',
                                                'disbursal_documents',
                                                'carry_forward',
                                                'dropped',
                                                'approved',
                                                'disbursed',
                                            ]
                                        )
                                    )
                            ),

                        Section::make('Disbursal Documents')
                            ->schema([

                                Hidden::make('documents_submitted')
                                    ->live()
                                    ->dehydrated()
                                    ->default(fn(?\Illuminate\Database\Eloquent\Model $record) => $record?->documents_submitted ?? false)
                                    ->formatStateUsing(
                                        fn($state, ?\Illuminate\Database\Eloquent\Model $record) =>
                                        $state ?: ($record?->documents_submitted ?? false)
                                    ),

                                FileUpload::make('disbursal_pdf')
                                    ->disk('public')
                                    ->directory('disbursal-documents')
                                    ->multiple()
                                    ->appendFiles()              // Keep old files and append new ones
                                    ->openable()
                                    ->downloadable()

                                    // Prevent removing uploaded files after document submission
                                    ->deletable(fn(?Customer $record) => ! ($record?->documents_submitted ?? false))

                                    // Optional: Prevent reordering after submission
                                    ->reorderable(fn(?Customer $record) => ! ($record?->documents_submitted ?? false))

                                    ->rules([
                                        function ($attribute, $value, $fail) {
                                            if (is_array($value)) {
                                                foreach ($value as $file) {
                                                    if ($file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                                                        if ($file->getMimeType() !== 'application/pdf') {
                                                            $fail('Only PDF files are allowed.');
                                                        }
                                                    }
                                                }
                                            }
                                        },
                                    ]),



                                Placeholder::make('document_submit_action')
                                    ->key('document_submit_action')
                                    ->label('')
                                    ->content('')
                                    ->hintAction(
                                        FormAction::make('submit_documents')
                                            ->label(
                                                fn(Get $get, ?\Illuminate\Database\Eloquent\Model $record) => ($get('documents_submitted') || ($record && session()->has("customer_{$record->id}_docs_submitted")))
                                                    ? 'Documents Submitted'
                                                    : 'Submit Documents'
                                            )
                                            ->color(
                                                fn(Get $get, ?\Illuminate\Database\Eloquent\Model $record) => ($get('documents_submitted') || ($record && session()->has("customer_{$record->id}_docs_submitted")))
                                                    ? 'success'
                                                    : 'warning'
                                            )
                                            ->requiresConfirmation()
                                            ->action(function (?\Illuminate\Database\Eloquent\Model $record, Set $set, Get $get) {

                                                if (! $record) {
                                                    return;
                                                }

                                                $uploadedFiles = $get('disbursal_pdf');

                                                // dd($uploadedFiles);

                                                if (blank($uploadedFiles)) {
                                                    Notification::make()
                                                        ->title('Please upload the Disbursal PDF first.')
                                                        ->danger()
                                                        ->send();
                                                    return;
                                                }

                                                $alreadySubmitted = (bool) $record->documents_submitted;

                                                // $record = CustomerJourneyService::finalize($record);
                                                // $set('documents_submitted', true);

                                                $filesArray = is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles];



                                                $existingDocuments = CustomerDocument::where('customer_id', $record->id)
                                                    ->pluck('document_name')
                                                    ->toArray();

                                                foreach ($filesArray as $singlePath) {

                                                    if ($singlePath instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {

                                                        $path = $singlePath->storePublicly(
                                                            'disbursal-documents',
                                                            'public'
                                                        );
                                                    } else {

                                                        $path = $singlePath;
                                                    }

                                                    CustomerDocument::create([
                                                        'customer_id'   => $record->id,
                                                        'document_type' => 'Disbursal Letter',
                                                        'document_name' => basename($path),
                                                        'document_path' => $path,
                                                        'uploaded_by'   => auth()->id(),
                                                    ]);
                                                }


                                                $record = CustomerJourneyService::finalize($record);

                                                // $alreadySubmitted = (bool) $record->documents_submitted;
                                                // $record->update(['documents_submitted' => true]);

                                                $set('documents_submitted', true);
                                                $set('disbursal_pdf', $filesArray);

                                                // session()->put("customer_{$record->id}_docs_submitted", true);
                                                // $record = CustomerJourneyService::finalize($record);
                                                Notification::make()
                                                    ->title($alreadySubmitted ? 'Documents updated successfully.' : 'Documents submitted successfully.')
                                                    ->success()
                                                    ->send();
                                            })
                                    ),
                            ])
                            ->columns(1)
                            ->live()

                            ->visible(
                                fn(Get $get) =>
                                in_array(
                                    strtolower((string) $get('journey_status')),
                                    [
                                        'sanctioned',
                                        // 'carry_forward',
                                        // 'dropped',
                                    ]
                                )
                            ),
                        // ---------------- GLOBAL REJECTION TERMINAL AREA ----------------
                        Section::make('Pipeline Exception / Rejection System')
                            ->schema([
                                Select::make('journey_not_approved_reason')
                                    ->label('Not Approved Stage Reason')
                                    ->options([
                                        'cibil_score' => 'CIBIL Score Issue',
                                        'defaulter_bounces' => 'Defaulter / Technical Bounces',
                                        'no_residence_proof' => 'No Residence Proof Found',
                                        'low_salary' => 'Low Salary Cap',
                                        'location_issue' => 'Location Blacklisted',
                                    ])
                                    ->required(),

                                Textarea::make('not_approved_remarks')
                                    ->label('Detailed Terminal Rejection Remarks')
                                    ->rows(2)
                                    ->columnSpanFull()
                                    ->required(),
                            ])
                            ->columns(2)
                            ->visible(fn(Get $get): bool => strtolower((string) $get('journey_status')) === 'not_approved'),
                    ])
                    // ->columnSpan(1)
                    ->columnSpanFull()
                    ->hidden(fn() => auth()->user()->hasRole('Employee')),
            ]);
    }
}
