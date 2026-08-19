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
            Section::make('Sales Snapshot — Read Only')
                ->columns(3)
                ->schema([
                    TextInput::make('sales_loan_type')->label('Loan Type')->disabled()->dehydrated(false),
                    TextInput::make('sales_disbursal_amount')->label('Loan Amount')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_rate')->label('Rate')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_cashback')->label('Cashback')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_subvention')->label('Subvention')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_docking')->label('Docking')->numeric()->disabled()->dehydrated(false),
                ]),

            Section::make('Latest Bank / MIS Position — Read Only')
                ->columns(3)
                ->schema([
                    TextInput::make('mis_lan_no')->label('LAN')->disabled()->dehydrated(false),
                    TextInput::make('mis_loan_type')->label('Loan Type As Per Bank')->disabled()->dehydrated(false),
                    TextInput::make('mis_disbursal_amount')->label('Loan Amount As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_roi')->label('Rate As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_cashback')->label('Cashback As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_subvention')->label('Subvention As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_docking')->label('Docking As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_processing_fee')->label('Processing Fee As Per Bank')->numeric()->disabled()->dehydrated(false),
                    DatePicker::make('mis_disbursal_date')->label('Bank Disbursal Date')->disabled()->dehydrated(false),
                    TextInput::make('cancellation_status')->label('Cancellation Status')->disabled()->dehydrated(false),
                    DatePicker::make('cancellation_date')->label('Cancellation Date')->disabled()->dehydrated(false),
                    TextInput::make('cancellation_recovery')->label('Cancellation Recovery')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_payment')->label('Payment As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('bank_commission_percentage')->label('Bank Commission %')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('bank_commission_amount')->label('Bank Commission')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_tds')->label('TDS As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_gst')->label('GST As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('actual_payable_amount')->label('Actual Payable As Per Bank')->numeric()->disabled()->dehydrated(false),
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
                ]),

            Section::make('Sales Achievement / Incentive Impact')
                ->columns(3)
                ->schema([
                    TextInput::make('achievement_before')->label('Achievement Before MIS')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('achievement_after')->label('Achievement After MIS')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('achievement_difference')->label('Achievement Difference')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('incentive_before')->label('Incentive Before MIS')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('incentive_after')->label('Incentive After MIS')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('incentive_difference')->label('Incentive Difference')->numeric()->disabled()->dehydrated(false),
                ]),

            Section::make('Accounts Settlement')
                ->columns(3)
                ->schema([
                    TextInput::make('gross_payable_amount')->label('Gross Payable')->numeric(),
                    TextInput::make('gst_rate')->label('Expected GST %')->numeric()->default(18),
                    TextInput::make('tds_rate')->label('Expected TDS %')->numeric()->default(2),
                    TextInput::make('expected_gst')->label('Expected GST')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('expected_tds')->label('Expected TDS')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('expected_payable_amount')->label('Expected Payable')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('gst_amount')->label('GST Used For Settlement')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('tds_amount')->label('TDS Used For Settlement')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('net_payable_amount')->label('Net Payable')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('payment_received_amount')->label('Payment Received From Transactions')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('surplus_amount')->label('Surplus')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('outstanding_amount')->label('Outstanding')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('recovery_received')->label('Recovery Received From Transactions')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('recovery_pending')->label('Recovery Pending')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('advance_received')->label('Advance Received From Transactions')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('advance_adjusted')->label('Advance Adjusted')->numeric(),
                    TextInput::make('advance_outstanding')->label('Advance Outstanding')->numeric()->disabled()->dehydrated(false),
                    DatePicker::make('payment_received_date')->label('Latest Payment Date')->disabled()->dehydrated(false),
                    TextInput::make('utr_number')->label('Latest UTR')->disabled()->dehydrated(false),
                    TextInput::make('invoice_number')->label('Invoice Number')->disabled()->dehydrated(false),
                    Select::make('payment_status')->label('Payment Status')->options([
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
