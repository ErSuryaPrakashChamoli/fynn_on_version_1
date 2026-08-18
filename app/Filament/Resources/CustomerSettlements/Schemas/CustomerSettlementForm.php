<?php

namespace App\Filament\Resources\CustomerSettlements\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerSettlementForm
{
    public static function configure(Schema $schema): Schema
    {
        $readonly = fn (TextInput $field) => $field->disabled()->dehydrated(false);

        return $schema->components([
            Section::make('Sales Snapshot')
                ->columns(3)
                ->schema([
                    TextInput::make('sales_loan_type')->label('Loan Type')->disabled()->dehydrated(false),
                    TextInput::make('sales_disbursal_amount')->label('Loan Amount')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_rate')->label('Rate')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_cashback')->label('Cashback')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_subvention')->label('Subvention')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_docking')->label('Docking')->numeric()->disabled()->dehydrated(false),
                ]),

            Section::make('Latest Bank / MIS Position')
                ->columns(3)
                ->schema([
                    TextInput::make('mis_lan_no')->label('LAN')->disabled()->dehydrated(false),
                    TextInput::make('mis_loan_type')->label('Loan Type As Per Bank')->disabled()->dehydrated(false),
                    TextInput::make('mis_disbursal_amount')->label('Loan Amount As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_roi')->label('Rate As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_cashback')->label('Cashback As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_subvention')->label('Subvention As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_docking')->label('Docking As Per Bank')->numeric()->disabled()->dehydrated(false),
                    DatePicker::make('mis_disbursal_date')->label('Bank Disbursal Date')->disabled()->dehydrated(false),
                    TextInput::make('mis_payment')->label('Payment As Per Bank')->numeric()->disabled()->dehydrated(false),
                ]),

            Section::make('Variance')
                ->columns(4)
                ->schema([
                    TextInput::make('variance_amount')->label('Loan Amount Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('variance_cashback')->label('Cashback Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('variance_subvention')->label('Subvention Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('variance_docking')->label('Docking Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('payment_difference')->label('Payment Difference')->numeric()->disabled()->dehydrated(false),
                ]),

            Section::make('Recovery & Advance')
                ->columns(3)
                ->schema([
                    TextInput::make('cancellation_status')->disabled()->dehydrated(false),
                    DatePicker::make('cancellation_date')->disabled()->dehydrated(false),
                    TextInput::make('cancellation_recovery')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('recovery_received')->numeric()->label('Recovery Received'),
                    TextInput::make('recovery_pending')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('advance_received')->numeric()->label('Advance Received'),
                    TextInput::make('advance_adjusted')->numeric()->label('Advance Adjusted'),
                    TextInput::make('advance_outstanding')->numeric()->disabled()->dehydrated(false),
                ]),

            Section::make('Accounts Settlement')
                ->columns(3)
                ->schema([
                    TextInput::make('gross_payable_amount')->label('Gross Payable')->numeric(),
                    TextInput::make('gst_rate')->label('GST %')->numeric()->default(18),
                    TextInput::make('tds_rate')->label('TDS %')->numeric()->default(2),
                    TextInput::make('gst_amount')->label('GST Amount')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('tds_amount')->label('TDS Amount')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('net_payable_amount')->label('Net Payable')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('payment_received_amount')->label('Payment Received')->numeric(),
                    TextInput::make('surplus_amount')->label('Surplus')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('outstanding_amount')->label('Outstanding')->numeric()->disabled()->dehydrated(false),
                    DatePicker::make('payment_received_date'),
                    TextInput::make('utr_number'),
                    TextInput::make('invoice_number'),
                    Select::make('payment_status')->options([
                        'pending' => 'Pending',
                        'partially_paid' => 'Partially Paid',
                        'paid' => 'Paid',
                        'hold' => 'Hold',
                    ])->required(),
                ]),

            Section::make('Accounts Remarks')
                ->schema([
                    Textarea::make('remarks')->rows(4),
                ]),
        ]);
    }
}
