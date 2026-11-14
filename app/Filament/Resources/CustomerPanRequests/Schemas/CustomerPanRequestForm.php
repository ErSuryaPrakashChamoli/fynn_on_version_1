<?php

namespace App\Filament\Resources\CustomerPanRequests\Schemas;

use App\Models\CustomerPanRequest;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class CustomerPanRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //

                Section::make('Request Details')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->schema([

                        TextInput::make('request_no')
                            ->disabled(),

                        Select::make('customer_id')
                            ->label('Existing Customer')
                            ->relationship('customer', 'customer_name')
                            ->searchable()
                            ->visible(fn (Get $get): bool => filled($get('customer_id')))
                            ->disabled(),

                        Select::make('matched_lead_id')
                            ->label('Existing Lead')
                            ->relationship('matchedLead', 'customer_name')
                            ->searchable()
                            ->visible(fn (Get $get): bool => filled($get('matched_lead_id')))
                            ->disabled(),

                        Select::make('request_for')
                            ->label('Requested For')
                            ->options([
                                CustomerPanRequest::FOR_CUSTOMER => 'Customer File',
                                CustomerPanRequest::FOR_LEAD => 'New Lead',
                            ])
                            ->disabled(),

                        TextInput::make('pan_number')
                            ->label('PAN Number')
                            ->disabled(),

                        Select::make('requested_bank_id')
                            ->relationship('requestedBank', 'bank_name')
                            ->searchable()
                            ->required(fn (Get $get): bool => $get('request_for') !== CustomerPanRequest::FOR_LEAD),

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
                            ->required(fn (Get $get): bool => $get('request_for') !== CustomerPanRequest::FOR_LEAD),

                        Textarea::make('reason')
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled(),

                    ])
                    ->columns(2),

                Section::make('Approval')
                    ->icon(Heroicon::OutlinedCheckBadge)
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
