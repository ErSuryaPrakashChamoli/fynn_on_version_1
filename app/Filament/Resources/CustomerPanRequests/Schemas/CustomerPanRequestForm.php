<?php

namespace App\Filament\Resources\CustomerPanRequests\Schemas;

use Filament\Schemas\Schema;


use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Forms\Components\CheckboxList;


class CustomerPanRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //

                Section::make('Request Details')
                    ->schema([

                        TextInput::make('request_no')
                            ->disabled(),

                        Select::make('customer_id')
                            ->relationship('customer', 'customer_name')
                            ->searchable()
                            ->disabled(),

                        Select::make('requested_bank_id')
                            ->relationship('requestedBank', 'bank_name')
                            ->searchable()
                            ->required(),

                        TextInput::make('requested_bank_name')
                            ->disabled(),

                        Select::make('requested_loan_type')
                            ->options([
                                'personal_loan' => 'Personal Loan',
                                'business_loan' => 'Business Loan',
                                'home_loan' => 'Home Loan',
                                'lap' => 'Loan Against Property',
                                'car_loan' => 'Car Loan',
                                'education_loan' => 'Education Loan',
                            ])
                            ->required(),

                        Textarea::make('reason')
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled(),

                    ])
                    ->columns(2),

                Section::make('Approval')
                    ->schema([

                        Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])
                            ->required(),

                        Textarea::make('remarks')
                            ->rows(3)
                            ->columnSpanFull(),

                    ]),


            ]);
    }
}
