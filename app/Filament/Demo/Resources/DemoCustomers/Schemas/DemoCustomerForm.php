<?php

namespace App\Filament\Demo\Resources\DemoCustomers\Schemas;

use App\Models\Demo\DemoCustomer;
use App\Support\Portal\IndianFaker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Create/edit form for a sandbox customer. Every field, including the
 * owner picker, reads and writes the demo database only.
 */
class DemoCustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Customer')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('customer_name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('mobile_no')
                            ->label('Mobile')
                            ->tel()
                            ->required()
                            ->maxLength(20),

                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),

                        TextInput::make('pan_number')
                            ->label('PAN')
                            ->maxLength(15),

                        Select::make('city')
                            ->options(array_combine(IndianFaker::CITIES, IndianFaker::CITIES)),

                        Select::make('demo_employee_id')
                            ->label('Owner')
                            ->relationship('employee', 'name')
                            ->preload(),
                    ]),

                Section::make('Eligibility')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('company_name')
                            ->label('Employer')
                            ->maxLength(255),

                        Select::make('company_category')
                            ->options(array_combine(IndianFaker::COMPANY_CATEGORIES, IndianFaker::COMPANY_CATEGORIES)),

                        TextInput::make('salary')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),

                        TextInput::make('eligible_loan_amount')
                            ->label('Eligible loan amount')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),

                        Select::make('journey_status')
                            ->label('Journey')
                            ->options(DemoCustomer::JOURNEY_STATUSES)
                            ->default('otp')
                            ->required(),

                        Select::make('eligibility_status')
                            ->label('Eligibility')
                            ->options([
                                'eligible' => 'Eligible',
                                'pending' => 'Pending',
                                'not_eligible' => 'Not Eligible',
                            ])
                            ->default('pending')
                            ->required(),
                    ]),
            ]);
    }
}
