<?php

namespace App\Services\Settlement;

use App\Models\CustomerSettlement;
use Illuminate\Support\Facades\DB;

class SettlementReconciliationService
{
    public function calculate(CustomerSettlement $settlement): CustomerSettlement
    {
        return DB::transaction(function () use ($settlement) {
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

            $gross = (float) ($settlement->gross_payable_amount ?? 0);

            if ($gross <= 0) {
                $gross = (float) ($settlement->expected_payable_amount ?? 0);
            }

            $gstRate = (float) ($settlement->gst_rate ?? 18);
            $tdsRate = (float) ($settlement->tds_rate ?? 2);

            $expectedGst = round($gross * ($gstRate / 100), 2);
            $expectedTds = round($gross * ($tdsRate / 100), 2);
            $expectedPayable = round($gross + $expectedGst - $expectedTds, 2);

            $settlement->expected_gst = $expectedGst;
            $settlement->expected_tds = $expectedTds;
            $settlement->expected_payable_amount = $expectedPayable;

            $bankGst = (float) ($settlement->mis_gst ?? 0);
            $bankTds = (float) ($settlement->mis_tds ?? 0);
            $bankActualPayable = (float) ($settlement->actual_payable_amount ?? 0);

            $settlement->variance_gst = $bankGst - $expectedGst;
            $settlement->variance_tds = $bankTds - $expectedTds;
            $settlement->variance_payable_amount = $bankActualPayable - $expectedPayable;

            // Bank MIS is authoritative for actual GST/TDS/payable whenever supplied.
            $settlement->gst_amount = $bankGst > 0 ? $bankGst : $expectedGst;
            $settlement->tds_amount = $bankTds > 0 ? $bankTds : $expectedTds;
            $settlement->net_payable_amount = $bankActualPayable > 0
                ? $bankActualPayable
                : $expectedPayable;

            $bankPayment = (float) ($settlement->mis_payment ?? 0);
            $settlement->payment_difference = $bankPayment - $settlement->net_payable_amount;

            $settlement->recovery_pending = max(
                (float) ($settlement->cancellation_recovery ?? 0) - (float) ($settlement->recovery_received ?? 0),
                0
            );

            $settlement->advance_outstanding = max(
                (float) ($settlement->advance_received ?? 0) - (float) ($settlement->advance_adjusted ?? 0),
                0
            );

            $received = (float) ($settlement->payment_received_amount ?? 0);
            $settlement->surplus_amount = max($received - $settlement->net_payable_amount, 0);
            $settlement->outstanding_amount = max($settlement->net_payable_amount - $received, 0);

            $hasCommercialVariance = collect([
                $settlement->variance_amount,
                $settlement->variance_cashback,
                $settlement->variance_subvention,
                $settlement->variance_docking,
            ])->contains(fn ($value) => abs((float) $value) > 0.01);

            $hasFinancialVariance = collect([
                $settlement->variance_gst,
                $settlement->variance_tds,
                $settlement->variance_payable_amount,
            ])->contains(fn ($value) => abs((float) $value) > 0.01);

            // Never move a case out of MIS review/verification while MIS is working.
            if (! in_array($settlement->status, ['mis_review', 'mis_verified'], true)) {
                if ($settlement->outstanding_amount <= 0 && $received > 0) {
                    $settlement->status = $settlement->recovery_pending > 0
                        ? 'recovery_pending'
                        : 'paid';
                } elseif ($received > 0) {
                    $settlement->status = 'partially_paid';
                } elseif ($hasCommercialVariance || $hasFinancialVariance) {
                    $settlement->status = 'variance';
                } else {
                    $settlement->status = 'accounts_review';
                }

                if ($settlement->status === 'paid' && $settlement->recovery_pending <= 0) {
                    $settlement->status = 'settled';
                }
            }

            if ($settlement->payment_status === 'paid' && $settlement->recovery_pending > 0) {
                $settlement->status = 'recovery_pending';
            }

            $settlement->save();

            return $settlement->fresh();
        });
    }
}
