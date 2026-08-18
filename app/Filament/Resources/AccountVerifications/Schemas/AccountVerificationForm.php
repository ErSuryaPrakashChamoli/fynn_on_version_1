<?php

namespace App\Filament\Resources\AccountVerifications\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccountVerificationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Sales Input — Read Only')
                ->columns(3)
                ->schema([
                    TextInput::make('sales_loan_type')->label('Loan Type')->disabled()->dehydrated(false),
                    TextInput::make('sales_loan_amount')->label('Loan Amount')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_rate')->label('Rate')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_cashback')->label('Cashback')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_subvention')->label('Subvention')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_docking')->label('Docking')->numeric()->disabled()->dehydrated(false),
                ]),

            Section::make('Bank MIS — MIS Team Only')
                ->columns(3)
                ->schema([
                    TextInput::make('mis_lan_no')->label('LAN')->required(),
                    TextInput::make('mis_loan_type')->label('Loan Type As Per Bank'),
                    TextInput::make('mis_disbursal_amount')->label('Loan Amount As Per Bank')->numeric(),
                    TextInput::make('mis_roi')->label('Rate As Per Bank')->numeric(),
                    TextInput::make('mis_cashback')->label('Cashback As Per Bank')->numeric(),
                    TextInput::make('mis_subvention')->label('Subvention As Per Bank')->numeric(),
                    TextInput::make('mis_docking')->label('Docking As Per Bank')->numeric(),
                    TextInput::make('mis_processing_fee')->label('Processing Fee As Per Bank')->numeric(),
                    DatePicker::make('mis_disbursal_date')->label('Bank Disbursal Date'),
                    Select::make('cancellation_status')->options([
                        'not_cancelled' => 'Not Cancelled',
                        'cancelled' => 'Cancelled',
                        'recovered' => 'Recovered',
                    ]),
                    DatePicker::make('cancellation_date'),
                    TextInput::make('cancellation_recovery')->numeric(),
                    TextInput::make('mis_payment')->label('Payment As Per Bank')->numeric(),
                    TextInput::make('bank_commission_percentage')->numeric(),
                    TextInput::make('bank_commission_amount')->numeric(),
                    TextInput::make('mis_tds')->numeric(),
                    TextInput::make('mis_gst')->numeric(),
                    TextInput::make('actual_payable_amount')->numeric(),
                ]),

            Section::make('Sales vs Bank Reconciliation')
                ->columns(3)
                ->schema([
                    TextInput::make('variance_amount')->label('Loan Amount Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('variance_cashback')->label('Cashback Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('variance_subvention')->label('Subvention Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('variance_docking')->label('Docking Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('variance_gst')->label('GST Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('variance_tds')->label('TDS Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('variance_payable_amount')->label('Payable Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('payment_difference')->label('Payment Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('achievement_difference')->label('Achievement Impact')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('incentive_difference')->label('Incentive Impact')->numeric()->disabled()->dehydrated(false),
                ]),

            Section::make('MIS Verification')
                ->schema([
                    Textarea::make('account_remark')->label('MIS Remarks')->rows(3),
                    Toggle::make('mis_verified')
                        ->label('MIS Verified')
                        ->helperText('Verify only after checking the bank MIS against the Sales values.'),
                ]),
        ]);
    }
}
