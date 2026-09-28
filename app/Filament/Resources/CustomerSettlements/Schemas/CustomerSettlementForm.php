<?php

namespace App\Filament\Resources\CustomerSettlements\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                    TextInput::make('sales_disbursal_amount')->label('Loan Amount')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('sales_rate')->label('Rate')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('sales_cashback')->label('Cashback')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('sales_subvention')->label('Subvention')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('sales_docking')->label('Docking')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                ]),

            Section::make('Latest Bank / MIS Position — Read Only')
                ->columns(3)
                ->schema([
                    TextInput::make('mis_lan_no')->label('LAN')->disabled()->dehydrated(false),
                    TextInput::make('mis_loan_type')->label('Loan Type As Per Bank')->disabled()->dehydrated(false),
                    TextInput::make('mis_disbursal_amount')->label('Loan Amount As Per Bank')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('mis_roi')->label('Rate As Per Bank')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('mis_cashback')->label('Cashback As Per Bank')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('mis_subvention')->label('Subvention As Per Bank')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('mis_docking')->label('Docking As Per Bank')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('mis_processing_fee')->label('Processing Fee As Per Bank')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    DatePicker::make('mis_disbursal_date')->label('Bank Disbursal Date')->disabled()->dehydrated(false),
                    TextInput::make('cancellation_status')->label('Cancellation Status')->disabled()->dehydrated(false),
                    DatePicker::make('cancellation_date')->label('Cancellation Date')->disabled()->dehydrated(false),
                    TextInput::make('cancellation_recovery')->label('Cancellation Recovery')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('mis_payment')->label('Payment As Per Bank')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('bank_commission_percentage')->label('Bank Commission %')->numeric()->disabled()->dehydrated(false),
                    TextInput::make('bank_commission_amount')->label('Bank Commission')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('mis_tds')->label('TDS As Per Bank')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('mis_gst')->label('GST As Per Bank')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('actual_payable_amount')->label('Actual Payable As Per Bank')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                ]),

            Section::make('Sales vs Bank Reconciliation')
                ->columns(3)
                ->schema([
                    TextInput::make('variance_amount')->label('Loan Amount Difference')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('variance_cashback')->label('Cashback Difference')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('variance_subvention')->label('Subvention Difference')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('variance_docking')->label('Docking Difference')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('variance_gst')->label('GST Difference')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('variance_tds')->label('TDS Difference')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('variance_payable_amount')->label('Payable Difference')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('payment_difference')->label('Payment Difference')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                ]),

            Section::make('Sales Achievement / Incentive Impact')
                ->columns(3)
                ->schema([
                    TextInput::make('achievement_before')->label('Achievement Before MIS')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('achievement_after')->label('Achievement After MIS')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('achievement_difference')->label('Achievement Difference')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('incentive_before')->label('Incentive Before MIS')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('incentive_after')->label('Incentive After MIS')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('incentive_difference')->label('Incentive Difference')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                ]),

            Section::make('Accounts Settlement')
                ->columns(3)
                ->schema([
                    TextInput::make('gross_payable_amount')->label('Gross Payable')->indianAmount(min: 0),
                    TextInput::make('gst_rate')->label('Expected GST %')->numeric()->default(18),
                    TextInput::make('tds_rate')->label('Expected TDS %')->numeric()->default(2),
                    TextInput::make('expected_gst')->label('Expected GST')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('expected_tds')->label('Expected TDS')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('expected_payable_amount')->label('Expected Payable')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('gst_amount')->label('GST Used For Settlement')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('tds_amount')->label('TDS Used For Settlement')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('net_payable_amount')->label('Net Payable')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('payment_received_amount')->label('Payment Received From Transactions')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('surplus_amount')->label('Surplus')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('outstanding_amount')->label('Outstanding')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('recovery_received')->label('Recovery Received From Transactions')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('recovery_pending')->label('Recovery Pending')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('advance_received')->label('Advance Received From Transactions')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
                    TextInput::make('advance_adjusted')->label('Advance Adjusted')->indianAmount(min: 0),
                    TextInput::make('advance_outstanding')->label('Advance Outstanding')->disabled()->dehydrated(false)->indianAmount(words: false, allowNegative: true),
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
