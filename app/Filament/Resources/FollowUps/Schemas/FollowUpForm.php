<?php

namespace App\Filament\Resources\FollowUps\Schemas;

use App\Models\AiCustomerRecord;
use App\Models\Bank;
use App\Models\Customer;
use Coolsam\Flatpickr\Forms\Components\Flatpickr;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FollowUpForm
{
    public static function configure(Schema $schema): Schema
    {
        $customer = Customer::find(request('customer'));
        $aiRecord = $customer ? null : AiCustomerRecord::find(request('ai_customer_record'));

        return $schema
            ->schema([

                Section::make('Customer Details')
                    ->description(
                        $aiRecord
                            ? 'This lead has not been converted into a customer profile yet — details below come from the AI-extracted document.'
                            : null
                    )
                    ->schema([

                        TextInput::make('customer_name')
                            ->label('Customer Name')
                            ->default($customer?->customer_name ?? $aiRecord?->value('customer_name'))
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('mobile_no')
                            ->label('Phone')
                            ->default($customer?->mobile_no ?? $aiRecord?->value('mobile_number'))
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('email')
                            ->label('Email Address')
                            ->default($customer?->email)
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('pan_number')
                            ->label('PAN Number')
                            ->default($customer?->pan_number)
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('current_location')
                            ->label('Current Location')
                            ->default($customer?->current_location)
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('job_location')
                            ->label('Job Location')
                            ->default($customer?->job_location)
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('salary')
                            ->label('Salary')
                            ->default(
                                $customer?->salary
                                    ? '₹'.number_format($customer->salary)
                                    : ''
                            )
                            ->disabled()
                            ->dehydrated(false),

                    ])
                    ->columns(2),

                Section::make('Follow Up')
                    ->schema([

                        DatePicker::make('follow_up_date')
                            ->required()
                            ->default(now()),

                        Select::make('follow_up_type')
                            ->options([
                                'Call' => 'Call',
                                'WhatsApp' => 'WhatsApp',
                                'Email' => 'Email',
                                'Visit' => 'Visit',
                            ])
                            ->required(),

                        // Select::make('status')
                        //     ->label('Status')
                        //     ->options([
                        //         'Pending' => 'Pending',
                        //         'Interested' => 'Interested',
                        //         'Not Interested' => 'Not Interested',
                        //         'Busy' => 'Busy',
                        //         'No Response' => 'No Response',
                        //         'Not Eligible' => 'Not Eligible',
                        //         'Eligible for Other Bank' => 'Eligible for Other Bank',
                        //     ])
                        //     ->default('Pending')
                        //     ->live()
                        //     ->required(),

                        // Select::make('status')
                        //     ->label('Status')
                        //     ->options([
                        //         'Pending' => 'Pending',
                        //         'Interested' => 'Interested',
                        //         'Not Interested' => 'Not Interested',
                        //         'Busy' => 'Busy',
                        //         'No Response' => 'No Response',
                        //         'Not Eligible' => 'Not Eligible',
                        //         'Eligible for Other Bank' => 'Eligible for Other Bank',
                        //     ])
                        //     ->default('Pending')
                        //     ->live()
                        //     ->required()
                        //     ->afterStateUpdated(function ($state, $set) {
                        //         if (in_array($state, ['Not Interested', 'Not Eligible'])) {
                        //             $set('next_follow_up_date', null);
                        //         }

                        //         if ($state !== 'Eligible for Other Bank') {
                        //             $set('bank_id', null);
                        //         }
                        //     }),

                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'Awaiting Low ROI' => 'Awaiting Low ROI',
                                'Awaiting PF Waiver' => 'Awaiting PF Waiver',
                                'Converted' => 'Converted',
                                'Delay Multifunding' => 'Delay Multifunding',
                                'Journey Started' => 'Journey Started',
                                'Lost' => 'Lost',
                                'On Hold' => 'On Hold',
                                'Out of Station' => 'Out of Station',
                            ])
                            ->default('Awaiting Low ROI')
                            ->live()
                            ->required()
                            ->afterStateUpdated(function ($state, $set) {
                                // Clear follow-up date for statuses where follow-up
                                // is not applicable.
                                if (in_array($state, ['Converted', 'Lost'])) {
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
                            ->live()
                            ->afterStateUpdated(function ($state, $set, $get) {
                                if ($get('status') !== 'Eligible for Other Bank') {
                                    $set('bank_id', null);
                                }
                            }),

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
                        //     ->helperText('Select both date and time for the next follow-up.'),

                        Flatpickr::make('next_follow_up_date')
                            ->label('Next Follow Up Date & Time')
                            ->time(true)
                            ->time24hr(false)
                            ->seconds(false)
                            ->minuteIncrement(15)
                            ->format('Y-m-d H:i')
                            ->displayFormat('d M Y h:i K')
                            ->minDate(today())
                            // ->required(
                            //     fn($get) => ! in_array($get('status'), [
                            //         'Not Interested',
                            //         'Not Eligible',
                            //     ])
                            // )
                            // ->visible(
                            //     fn($get) => ! in_array($get('status'), [
                            //         'Not Interested',
                            //         'Not Eligible',
                            //     ])
                            // )
                            ->required(
                                fn ($get) => ! in_array($get('status'), [
                                    'Converted',
                                    'Lost',
                                ])
                            )
                            ->visible(
                                fn ($get) => ! in_array($get('status'), [
                                    'Converted',
                                    'Lost',
                                ])
                            )
                            ->placeholder('Select date & time')
                            ->suffixIcon('heroicon-m-calendar')
                            ->helperText('Select date and time for the next follow-up.'),

                        Textarea::make('remarks')
                            ->rows(5)
                            ->required()
                            ->columnSpanFull(),

                        Hidden::make('customer_id')
                            ->default(fn () => request()->query('customer'))
                            ->dehydrated(true)
                            ->required(fn (Get $get) => blank($get('ai_customer_record_id'))),

                        Hidden::make('ai_customer_record_id')
                            ->default(fn () => request()->query('ai_customer_record'))
                            ->dehydrated(true)
                            ->required(fn (Get $get) => blank($get('customer_id'))),

                        Hidden::make('employee_id')
                            ->default(fn () => auth()->user()?->employee?->id)
                            ->dehydrated(true),

                    ])
                    ->columns(2),

            ]);
    }
}
