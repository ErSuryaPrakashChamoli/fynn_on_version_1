<?php

namespace App\Services\Settlement;

use App\Models\CustomerSettlement;

class SettlementReconciliationService
{
    public function calculate(CustomerSettlement $settlement): CustomerSettlement
    {
        $salesLoan = (float) ($settlement->sales_disbursal_amount ?? 0);
        $bankLoan = (float) ($settlement->mis_disbursal_amount ?? 0);
        $salesCashback = (float) ($settlement->sales_cashback ?? 0);
        $bankCashback = (float) ($settlement->mis_cashback ?? 0);
        $salesSubvention = (float) ($settlement->sales_subvention ?? 0);
        $bankSubvention = (float) ($settlement->mis_subvention ?? 0);
        $salesDocking = (float) ($settlement->sales_docking ?? 0);
        $bankDocking = (float) ($settlement->mis_docking ?? 0);

        $settlement->variance_amount = $bankLoan - $salesLoan;
        $settlement->variance_cashback = $bankCashback - $salesCashback;
        $settlement->variance_subvention = $bankSubvention - $salesSubvention;
        $settlement->variance_docking = $bankDocking - $salesDocking;

        $bankPayment = (float) ($settlement->mis_payment ?? 0);
        $settlement->payment_difference = $bankPayment - (float) ($settlement->expected_payable_amount ?? 0);

        $settlement->recovery_pending = max(
            (float) ($settlement->cancellation_recovery ?? 0) - (float) ($settlement->recovery_received ?? 0),
            0
        );

        $settlement->advance_outstanding = max(
            (float) ($settlement->advance_received ?? 0) - (float) ($settlement->advance_adjusted ?? 0),
            0
        );

        $gross = (float) ($settlement->gross_payable_amount ?: $settlement->actual_payable_amount ?: $settlement->expected_payable_amount ?: 0);
        $settlement->gst_amount = round($gross * ((float) ($settlement->gst_rate ?? 18) / 100), 2);
        $settlement->tds_amount = round($gross * ((float) ($settlement->tds_rate ?? 2) / 100), 2);
        $settlement->net_payable_amount = round($gross + $settlement->gst_amount - $settlement->tds_amount, 2);

        $received = (float) ($settlement->payment_received_amount ?? 0);
        $settlement->surplus_amount = max($received - $settlement->net_payable_amount, 0);
        $settlement->outstanding_amount = max($settlement->net_payable_amount - $received, 0);

        $hasVariance = collect([
            $settlement->variance_amount,
            $settlement->variance_cashback,
            $settlement->variance_subvention,
            $settlement->variance_docking,
        ])->contains(fn ($value) => abs((float) $value) > 0.01);

        if ($settlement->status !== 'mis_review' && $settlement->status !== 'mis_verified') {
            $settlement->status = $hasVariance ? 'variance' : 'accounts_review';
        }

        $settlement->save();

        return $settlement->fresh();
    }
}
