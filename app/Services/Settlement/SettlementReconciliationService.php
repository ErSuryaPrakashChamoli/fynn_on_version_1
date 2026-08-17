<?php

namespace App\Services\Settlement;

use App\Models\CustomerSettlement;
use Illuminate\Support\Facades\DB;

class SettlementReconciliationService
{
    public function calculate(
        CustomerSettlement $settlement
    ): CustomerSettlement {

        $salesDisbursal = (float) ($settlement->sales_disbursal_amount ?? 0);
        $misDisbursal = (float) ($settlement->mis_disbursal_amount ?? 0);

        $salesCashback = (float) ($settlement->sales_cashback ?? 0);
        $misCashback = (float) ($settlement->mis_cashback ?? 0);

        $salesSubvention = (float) ($settlement->sales_subvention ?? 0);
        $misSubvention = (float) ($settlement->mis_subvention ?? 0);

        $salesDocking = (float) ($settlement->sales_docking ?? 0);
        $misDocking = (float) ($settlement->mis_docking ?? 0);

        $settlement->variance_amount =
            $misDisbursal - $salesDisbursal;

        $settlement->variance_cashback =
            $misCashback - $salesCashback;

        $settlement->variance_subvention =
            $misSubvention - $salesSubvention;

        $settlement->variance_docking =
            $misDocking - $salesDocking;

        /*
         * If there is ANY difference,
         * mark settlement as variance.
         */
        $hasVariance =
            abs((float) $settlement->variance_amount) > 0.01 ||
            abs((float) $settlement->variance_cashback) > 0.01 ||
            abs((float) $settlement->variance_subvention) > 0.01 ||
            abs((float) $settlement->variance_docking) > 0.01;

        if ($hasVariance) {
            $settlement->status = 'variance';
        }

        $settlement->save();

        return $settlement->fresh();
    }
}
