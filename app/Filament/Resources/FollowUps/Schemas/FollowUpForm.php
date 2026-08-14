<?php

namespace App\Filament\Resources\FollowUps\Schemas;

use App\Models\Bank;
use App\Models\Customer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Forms\Components\DateTimePicker;
use Coolsam\Flatpickr\Forms\Components\Flatpickr;

class FollowUpForm
{
    public static function configure(Schema $schema): Schema
    {
        $customer = Customer::find(request('customer'));

        return $schema
            ->schema([

                Section::make('Customer Details')
                    ->schema([

                        TextInput::make('customer_name')
                            ->label('Customer Name')
                            ->default($customer?->customer_name)
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('mobile_no')
                            ->label('Phone')
                            ->default($customer?->mobile_no)
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
                                    ? '₹' . number_format($customer->salary)
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
                            ->default('Pending')
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
                                fn() => Bank::query()
                                    ->where('is_active', 1)
                                    ->orderBy('bank_name')
                                    ->pluck('bank_name', 'id')
                                    ->toArray()
                            )
                            ->searchable()
                            ->preload()
                            ->required(
                                fn($get) =>
                                $get('status') === 'Eligible for Other Bank'
                            )
                            ->visible(
                                fn($get) =>
                                $get('status') === 'Eligible for Other Bank'
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
                            ->required(
                                fn($get) => ! in_array($get('status'), [
                                    'Not Interested',
                                    'Not Eligible',
                                ])
                            )
                            ->visible(
                                fn($get) => ! in_array($get('status'), [
                                    'Not Interested',
                                    'Not Eligible',
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
                            ->default(fn() => request()->query('customer'))
                            ->dehydrated(true)
                            ->required(),

                        Hidden::make('employee_id')
                            ->default(fn() => auth()->user()?->employee?->id)
                            ->dehydrated(true)
                            ->required(),

                    ])
                    ->columns(2),

            ]);
    }
}
