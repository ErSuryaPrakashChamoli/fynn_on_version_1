<?php

namespace App\Filament\Resources\FollowUps\Schemas;

use App\Models\Customer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
// use Filament\Forms\Components\Section;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Hidden;

class FollowUpForm
{
    public static function configure(Schema $schema): Schema
    {
        $customer = Customer::find(request('customer'));



        // Changed ->components() to ->schema() at the root level
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

                        Select::make('status')
                            ->options([
                                'Pending' => 'Pending',
                                'Interested' => 'Interested',
                                'Not Interested' => 'Not Interested',
                                'Busy' => 'Busy',
                                'No Response' => 'No Response',
                            ])
                            ->default('Pending')
                            ->required(),

                        // DatePicker::make('next_follow_up_date'),

                        DatePicker::make('next_follow_up_date')
                            ->label('Next Follow Up Date')
                            ->displayFormat('d F Y')
                            ->native(false)
                            ->suffixIcon('heroicon-m-calendar')
                            ->minDate(now()->addDay())
                            ->required(),

                        Textarea::make('remarks')
                            ->rows(5)
                            ->required()
                            ->columnSpanFull(), // Makes the textarea span across both columns cleanly

                        Hidden::make('customer_id')
                            ->default(fn() => request()->query('customer'))
                            ->dehydrated(true)
                            ->required()

                    ])
                    ->columns(2),

            ]);
    }
}
