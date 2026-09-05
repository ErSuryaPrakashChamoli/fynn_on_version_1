<?php

namespace App\Filament\Resources\AssignedLeads\Schemas;

use App\Models\Bank;
use App\Models\City;
use App\Models\CustomerAssignment;
use Coolsam\Flatpickr\Forms\Components\Flatpickr;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class AssignedLeadForm
{
    /**
     * Once an assignment already owns a real Customer record, these prospect
     * fields belong to that Customer and should be edited via the Customers
     * resource instead — not silently overwritten from here.
     */
    protected static function isLockedToCustomer(?CustomerAssignment $record): bool
    {
        return filled($record?->customer_id);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Prospect Details')
                    ->description(fn (?CustomerAssignment $record) => self::isLockedToCustomer($record)
                        ? 'This lead already has a linked Customer profile — edit these details from the Customers resource instead.'
                        : 'Name and mobile number come from the source record. Fill in the rest so this lead is ready to convert to a customer.')
                    ->schema([
                        TextInput::make('customer_name')
                            ->label('Customer Name')
                            ->default(fn (?CustomerAssignment $record) => $record?->customer?->customer_name
                                ?? $record?->aiCustomerRecord?->value('customer_name'))
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('mobile_no')
                            ->label('Mobile Number')
                            ->default(fn (?CustomerAssignment $record) => $record?->customer?->mobile_no
                                ?? $record?->aiCustomerRecord?->value('mobile_number'))
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('pan_number')
                            ->label('PAN Number')
                            ->default(fn (?CustomerAssignment $record) => $record?->customer?->pan_number
                                ?? $record?->aiCustomerRecord?->value('pan_number'))
                            ->maxLength(10)
                            ->minLength(10)
                            ->placeholder('ABCDE1234F')
                            ->formatStateUsing(fn ($state) => filled($state) ? strtoupper($state) : $state)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? strtoupper($state) : $state)
                            ->rules(['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'])
                            ->validationMessages(['regex' => 'Please enter a valid PAN number like ABCDE1234F.'])
                            ->disabled(fn (?CustomerAssignment $record) => self::isLockedToCustomer($record))
                            ->dehydrated(fn (?CustomerAssignment $record) => ! self::isLockedToCustomer($record)),

                        TextInput::make('email')
                            ->label('Email Address')
                            ->email()
                            ->default(fn (?CustomerAssignment $record) => $record?->customer?->email
                                ?? $record?->aiCustomerRecord?->value('email'))
                            ->disabled(fn (?CustomerAssignment $record) => self::isLockedToCustomer($record))
                            ->dehydrated(fn (?CustomerAssignment $record) => ! self::isLockedToCustomer($record)),

                        Select::make('current_location')
                            ->label('Current Location')
                            ->searchable()
                            ->preload()
                            ->options(fn () => City::query()->where('is_active', 1)->orderBy('city')->get()->mapWithKeys(fn ($item) => [$item->city => "{$item->city}, {$item->state}"]))
                            ->default(fn (?CustomerAssignment $record) => $record?->customer?->current_location
                                ?? $record?->aiCustomerRecord?->value('current_location'))
                            ->disabled(fn (?CustomerAssignment $record) => self::isLockedToCustomer($record))
                            ->dehydrated(fn (?CustomerAssignment $record) => ! self::isLockedToCustomer($record)),

                        Select::make('job_location')
                            ->label('Job Location')
                            ->searchable()
                            ->preload()
                            ->options(fn () => City::query()->where('is_active', 1)->orderBy('city')->get()->pluck('city', 'city'))
                            ->default(fn (?CustomerAssignment $record) => $record?->customer?->job_location
                                ?? $record?->aiCustomerRecord?->value('job_location'))
                            ->disabled(fn (?CustomerAssignment $record) => self::isLockedToCustomer($record))
                            ->dehydrated(fn (?CustomerAssignment $record) => ! self::isLockedToCustomer($record)),

                        Select::make('residence_location')
                            ->label('Residence Location')
                            ->searchable()
                            ->preload()
                            ->options(fn () => City::query()->where('is_active', 1)->orderBy('city')->get()->mapWithKeys(fn ($item) => [$item->city => "{$item->city}, {$item->state}"]))
                            ->default(fn (?CustomerAssignment $record) => $record?->customer?->residence_location
                                ?? $record?->aiCustomerRecord?->value('residence_location'))
                            ->disabled(fn (?CustomerAssignment $record) => self::isLockedToCustomer($record))
                            ->dehydrated(fn (?CustomerAssignment $record) => ! self::isLockedToCustomer($record)),

                        TextInput::make('salary')
                            ->label('Salary')
                            ->prefix('₹')
                            ->live()
                            ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null)
                            ->afterStateUpdated(function ($state, Set $set) {
                                $value = preg_replace('/[^0-9]/', '', (string) $state);

                                if ($value !== '') {
                                    $set('salary', indianCurrencyFormat($value));
                                }
                            })
                            ->dehydrateStateUsing(fn ($state) => preg_replace('/[^0-9]/', '', (string) $state))
                            ->default(fn (?CustomerAssignment $record) => $record?->customer?->salary
                                ?? $record?->aiCustomerRecord?->value('salary'))
                            ->disabled(fn (?CustomerAssignment $record) => self::isLockedToCustomer($record))
                            ->dehydrated(fn (?CustomerAssignment $record) => ! self::isLockedToCustomer($record)),
                    ])
                    ->columns(2),

                Section::make('Follow Up Details')
                    ->description('This follow-up is recorded against today. The next follow-up date you set here replaces the one set previously — every change is kept in the follow-up log below.')
                    ->schema([
                        Select::make('follow_up_type')
                            ->options([
                                'Call' => 'Call',
                                'WhatsApp' => 'WhatsApp',
                                'Email' => 'Email',
                                'Visit' => 'Visit',
                            ])
                            ->required(),

                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'Pending' => 'Pending',
                                'Interested' => 'Interested',
                                'Not Interested' => 'Not Interested',
                                'Busy' => 'Busy',
                                'No Response' => 'No Response',
                                'Not Eligible' => 'Not Eligible',
                                'Eligible for Other Bank' => 'Eligible for Other Bank',
                            ])
                            ->default(fn (?CustomerAssignment $record) => $record?->latestFollowUp()?->status ?? 'Pending')
                            ->live()
                            ->required()
                            ->afterStateUpdated(function ($state, $set) {
                                if (in_array($state, ['Not Interested', 'Not Eligible'])) {
                                    $set('next_follow_up_date', null);
                                }

                                if ($state !== 'Eligible for Other Bank') {
                                    $set('bank_id', null);
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
                            ->default(fn (?CustomerAssignment $record) => $record?->latestFollowUp()?->bank_id)
                            ->searchable()
                            ->preload()
                            ->required(fn (Get $get) => $get('status') === 'Eligible for Other Bank')
                            ->visible(fn (Get $get) => $get('status') === 'Eligible for Other Bank')
                            ->live(),

                        Flatpickr::make('next_follow_up_date')
                            ->label('Next Follow Up Date & Time')
                            ->time(true)
                            ->time24hr(false)
                            ->seconds(false)
                            ->minuteIncrement(15)
                            ->format('Y-m-d H:i')
                            ->displayFormat('d M Y h:i K')
                            ->minDate(today())
                            ->default(fn (?CustomerAssignment $record) => $record?->latestFollowUp()?->next_follow_up_date)
                            ->required(fn (Get $get) => ! in_array($get('status'), ['Not Interested', 'Not Eligible']))
                            ->visible(fn (Get $get) => ! in_array($get('status'), ['Not Interested', 'Not Eligible']))
                            ->placeholder('Select date & time')
                            ->suffixIcon('heroicon-m-calendar'),

                        Textarea::make('remarks')
                            ->rows(4)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
