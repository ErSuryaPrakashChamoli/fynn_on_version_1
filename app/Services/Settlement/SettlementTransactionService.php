<?php

namespace App\Services\Settlement;

use App\Models\CustomerSettlement;
use Illuminate\Support\Facades\DB;

class SettlementTransactionService
{
    public function sync(CustomerSettlement $settlement): CustomerSettlement
    {
        return DB::transaction(function () use ($settlement) {
            $transactions = $settlement->transactions()->get();

            $payment = (float) $transactions->where('type', 'payment')->sum('amount');
            $recovery = (float) $transactions->where('type', 'recovery')->sum('amount');
            $advance = (float) $transactions->where('type', 'advance')->sum('amount');
            $adjustment = (float) $transactions->where('type', 'adjustment')->sum('amount');
            $refund = (float) $transactions->where('type', 'refund')->sum('amount');

            // Positive adjustment increases payable; refund reduces received funds.
            $netAdjustment = $adjustment - $refund;

            $settlement->payment_received_amount = $payment;
            $settlement->recovery_received = $recovery;
            $settlement->advance_received = $advance;

            $settlement->advance_outstanding = max(
                0,
                $advance - (float) $settlement->advance_adjusted
            );

            $settlement->recovery_pending = max(
                0,
                (float) $settlement->cancellation_recovery - $recovery
            );

            $settlement->gross_payable_amount = max(
                0,
                (float) $settlement->gross_payable_amount + $netAdjustment
            );

            $settlement->net_payable_amount = max(
                0,
                (float) $settlement->net_payable_amount + $netAdjustment
            );

            // Any transaction made by Accounts means the MIS-verified case has entered Accounts review.
            if (
                auth()->user()?->hasAnyRole(['Accounts', 'Admin']) &&
                $settlement->status === 'mis_verified'
            ) {
                $settlement->status = 'accounts_review';
            }

            $netPayable = (float) $settlement->net_payable_amount;
            $settlement->surplus_amount = max(0, $payment - $netPayable);
            $settlement->outstanding_amount = max(0, $netPayable - $payment);

            // Never move a case out of MIS review/verification while MIS is
            // working — mirrors the same guard in
            // SettlementReconciliationService::calculate(). The role-gated
            // block above is the one sanctioned way out of mis_verified
            // (into accounts_review); once there, this block is free to
            // refine the status further based on the transaction totals.
            if (! in_array($settlement->status, ['mis_review', 'mis_verified'], true)) {
                if ($recovery > 0 && $settlement->recovery_pending > 0) {
                    $settlement->status = 'recovery_pending';
                } elseif ($payment <= 0) {
                    $settlement->status = 'payment_pending';
                } elseif ($payment < $netPayable) {
                    $settlement->status = 'partially_paid';
                } else {
                    $settlement->status = 'paid';
                }

                if (
                    $payment >= $netPayable &&
                    $settlement->recovery_pending <= 0
                ) {
                    $settlement->status = 'settled';
                }
            }

            $settlement->save();

            return $settlement->refresh();
        });
    }
}
