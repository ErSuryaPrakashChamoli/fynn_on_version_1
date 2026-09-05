<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Models\Bank;
use App\Models\City;
use Coolsam\Flatpickr\Forms\Components\Flatpickr;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //

                Section::make('Prospect Details (Manual Entry)')
                    ->schema([

                        TextInput::make('customer_name')
                            ->label('Customer Name')
                            ->required()
                            ->maxLength(255)
                            ->live()
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

                        TextInput::make('pan_number')
                            ->label('PAN Number')
                            // ->required()
                            ->live()
                            ->maxLength(10)
                            ->minLength(10)
                            ->afterStateUpdated(function ($state, callable $set, $livewire) {
                                $state = strtoupper($state);

                                $set('pan_number', $state);

                                if (strlen($state) === 10) {
                                    $livewire->validateOnly('data.pan_number');
                                }
                            })
                            ->dehydrateStateUsing(fn ($state) => strtoupper($state))

                            ->rules([
                                'nullable',
                                'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
                            ])
                            ->unique(
                                table: 'leads',
                                column: 'pan_number',
                                ignoreRecord: true,
                            )

                            ->placeholder('ABCDE1234F')
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
                            ->live()
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

                Section::make('Initial Follow Up Details')
                    ->description('This follow-up is recorded against today. The next follow-up date decides when the lead is due — changing it replaces the previous date and is kept in the lead\'s follow-up log.')
                    ->schema([

                        Select::make('follow_up_type')
                            ->options(['Call' => 'Call', 'WhatsApp' => 'WhatsApp', 'Email' => 'Email', 'Visit' => 'Visit'])
                            ->required(),

                        Select::make('status')
                            ->options([
                                'Pending' => 'Pending',
                                'Interested' => 'Interested',
                                'Not Interested' => 'Not Interested',
                                'Busy' => 'Busy',
                                'No Response' => 'No Response',
                                'Not Eligible' => 'Not Eligible',
                                'Eligible for Other Bank' => 'Eligible for Other Bank',
                            ])
                            ->default('Pending')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, $set) {

                                // Clear bank if status is not eligible for other bank
                                if ($state !== 'Eligible for Other Bank') {
                                    $set('bank_id', null);
                                }

                                // Clear next follow-up date for closed statuses
                                if (in_array($state, ['Not Interested', 'Not Eligible'])) {
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

                        Hidden::make('employee_id')
                            ->default(fn () => auth()->user()->employee?->id)
                            ->dehydrated(true),
                    ])->columns(2),
            ]);
    }
}
