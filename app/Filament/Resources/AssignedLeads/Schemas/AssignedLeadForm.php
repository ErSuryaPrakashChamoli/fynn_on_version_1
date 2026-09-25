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

    /**
     * Edit pages fill from the assignment row, which owns none of these
     * fields, so ->default() never applies there. Resolve the prefilled
     * prospect details (linked Customer first, then the AI-extracted record)
     * and the latest follow-up's status, bank and next date instead.
     *
     * @return array<string, mixed>
     */
    public static function fillData(CustomerAssignment $record): array
    {
        $customer = $record->customer;
        $aiRecord = $record->aiCustomerRecord;
        $latestFollowUp = $record->latestFollowUp();

        return [
            'customer_name' => $customer?->customer_name ?? $aiRecord?->value('customer_name'),
            'mobile_no' => $customer?->mobile_no ?? $aiRecord?->value('mobile_number'),
            'pan_number' => $customer?->pan_number ?? $aiRecord?->value('pan_number'),
            'email' => $customer?->email ?? $aiRecord?->value('email'),
            'current_location' => $customer?->current_location ?? $aiRecord?->value('current_location'),
            'job_location' => $customer?->job_location ?? $aiRecord?->value('job_location'),
            'residence_location' => $customer?->residence_location ?? $aiRecord?->value('residence_location'),
            'salary' => $customer?->salary ?? $aiRecord?->value('salary'),
            'status' => $latestFollowUp?->status ?? 'Pending',
            'bank_id' => $latestFollowUp?->bank_id,
            'next_follow_up_date' => $latestFollowUp?->next_follow_up_date?->format('Y-m-d H:i'),
        ];
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
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('mobile_no')
                            ->label('Mobile Number')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('pan_number')
                            ->label('PAN Number')
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
                            ->disabled(fn (?CustomerAssignment $record) => self::isLockedToCustomer($record))
                            ->dehydrated(fn (?CustomerAssignment $record) => ! self::isLockedToCustomer($record)),

                        Select::make('current_location')
                            ->label('Current Location')
                            ->searchable()
                            ->preload()
                            ->options(fn () => City::query()->where('is_active', 1)->orderBy('city')->get()->mapWithKeys(fn ($item) => [$item->city => "{$item->city}, {$item->state}"]))
                            ->disabled(fn (?CustomerAssignment $record) => self::isLockedToCustomer($record))
                            ->dehydrated(fn (?CustomerAssignment $record) => ! self::isLockedToCustomer($record)),

                        Select::make('job_location')
                            ->label('Job Location')
                            ->searchable()
                            ->preload()
                            ->options(fn () => City::query()->where('is_active', 1)->orderBy('city')->get()->pluck('city', 'city'))
                            ->disabled(fn (?CustomerAssignment $record) => self::isLockedToCustomer($record))
                            ->dehydrated(fn (?CustomerAssignment $record) => ! self::isLockedToCustomer($record)),

                        Select::make('residence_location')
                            ->label('Residence Location')
                            ->searchable()
                            ->preload()
                            ->options(fn () => City::query()->where('is_active', 1)->orderBy('city')->get()->mapWithKeys(fn ($item) => [$item->city => "{$item->city}, {$item->state}"]))
                            ->disabled(fn (?CustomerAssignment $record) => self::isLockedToCustomer($record))
                            ->dehydrated(fn (?CustomerAssignment $record) => ! self::isLockedToCustomer($record)),

                        TextInput::make('salary')
                            ->label('Salary')
                            ->prefix('₹')
                            ->amountInWords()
                            ->live()
                            ->formatStateUsing(fn ($state) => filled($state) ? indianCurrencyFormat($state) : null)
                            ->afterStateUpdated(function ($state, Set $set) {
                                $value = preg_replace('/[^0-9]/', '', (string) $state);

                                if ($value !== '') {
                                    $set('salary', indianCurrencyFormat($value));
                                }
                            })
                            ->dehydrateStateUsing(fn ($state) => preg_replace('/[^0-9]/', '', (string) $state))
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
                            ->options(CustomerAssignment::FOLLOW_UP_STATUSES)
                            ->live()
                            ->required()
                            ->afterStateUpdated(function ($state, $set) {
                                if (in_array($state, CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES)) {
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
                            ->required(fn (Get $get) => ! in_array($get('status'), CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES))
                            ->visible(fn (Get $get) => ! in_array($get('status'), CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES))
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
