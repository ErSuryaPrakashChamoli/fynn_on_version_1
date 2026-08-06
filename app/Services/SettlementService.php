<?php

namespace App\Services;

use App\Models\CustomerSettlement;
use App\Models\MisBatchRow;
use Illuminate\Support\Facades\DB;

class SettlementService
{
    /**
     * Create or Update Settlement from MIS Row.
     */
    public function process(MisBatchRow $row): CustomerSettlement
    {
        return DB::transaction(function () use ($row) {

            if (!$row->customer_id) {
                throw new \Exception('Customer not matched.');
            }

            $customer = $row->customer;

            /*
            |--------------------------------------------------------------------------
            | Sales Values
            |--------------------------------------------------------------------------
            */

            $salesDisbursal = (float) ($customer->approved_loan_amount ?? 0);
            $salesCashback = (float) ($customer->cashback ?? 0);
            $salesSubvention = (float) ($customer->subvention ?? 0);
            $salesDocking = (float) ($customer->docking ?? 0);

            /*
            |--------------------------------------------------------------------------
            | MIS Values
            |--------------------------------------------------------------------------
            */

            $misDisbursal = (float) ($row->loan_amount ?? 0);
            $misCashback = (float) ($row->cashback ?? 0);
            $misSubvention = (float) ($row->subvention ?? 0);
            $misDocking = (float) ($row->docking ?? 0);

            /*
            |--------------------------------------------------------------------------
            | Variance
            |--------------------------------------------------------------------------
            */

            $varianceAmount = $misDisbursal - $salesDisbursal;
            $varianceCashback = $misCashback - $salesCashback;
            $varianceSubvention = $misSubvention - $salesSubvention;
            $varianceDocking = $misDocking - $salesDocking;

            /*
            |--------------------------------------------------------------------------
            | Company Commission
            |--------------------------------------------------------------------------
            */

            $companyCommission =
                $misCashback +
                $misSubvention -
                $misDocking;

            /*
            |--------------------------------------------------------------------------
            | Sales Incentive
            |--------------------------------------------------------------------------
            |
            | Replace this later with your Incentive Calculator.
            |
            */

            $salesIncentive = 0;

            /*
            |--------------------------------------------------------------------------
            | Existing Settlement
            |--------------------------------------------------------------------------
            */

            $settlement = CustomerSettlement::firstOrNew([
                'customer_id' => $customer->id,
                'mis_batch_id' => $row->mis_batch_id,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Fill
            |--------------------------------------------------------------------------
            */

            $settlement->version = $settlement->exists
                ? $settlement->version + 1
                : 1;

            /*
            |--------------------------------------------------------------------------
            | Sales Snapshot
            |--------------------------------------------------------------------------
            */

            $settlement->sales_disbursal_amount = $salesDisbursal;
            $settlement->sales_cashback = $salesCashback;
            $settlement->sales_subvention = $salesSubvention;
            $settlement->sales_docking = $salesDocking;

            /*
            |--------------------------------------------------------------------------
            | MIS Snapshot
            |--------------------------------------------------------------------------
            */

            $settlement->mis_disbursal_amount = $misDisbursal;
            $settlement->mis_cashback = $misCashback;
            $settlement->mis_subvention = $misSubvention;
            $settlement->mis_docking = $misDocking;

            $settlement->mis_processing_fee = $row->processing_fee;
            $settlement->mis_roi = $row->roi;
            $settlement->mis_lan_no = $row->lan_no;
            $settlement->mis_disbursal_date = $row->disbursal_date;

            /*
            |--------------------------------------------------------------------------
            | Calculated
            |--------------------------------------------------------------------------
            */

            $settlement->company_commission = $companyCommission;
            $settlement->sales_incentive = $salesIncentive;

            /*
            |--------------------------------------------------------------------------
            | Variance
            |--------------------------------------------------------------------------
            */

            $settlement->variance_amount = $varianceAmount;
            $settlement->variance_cashback = $varianceCashback;
            $settlement->variance_subvention = $varianceSubvention;
            $settlement->variance_docking = $varianceDocking;

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $settlement->status = 'pending';

            $settlement->save();

            return $settlement;
        });
    }
}
