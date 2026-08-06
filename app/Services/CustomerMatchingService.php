<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\MisBatchRow;

class CustomerMatchingService
{
    /**
     * Match a MIS row with an existing customer.
     */
    public function match(MisBatchRow $row): ?Customer
    {
        $customer = null;

        /*
        |--------------------------------------------------------------------------
        | 1. Match by Application Number
        |--------------------------------------------------------------------------
        */

        if (! empty($row->application_no)) {
            $customer = Customer::where('application_no', trim($row->application_no))->first();
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Match by LAN Number
        |--------------------------------------------------------------------------
        */

        if (! $customer && ! empty($row->lan_no)) {
            $customer = Customer::where('lan_no', trim($row->lan_no))->first();
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Match by PAN Number
        |--------------------------------------------------------------------------
        */

        if (! $customer && ! empty($row->pan_number)) {
            $customer = Customer::whereRaw('UPPER(pan_number) = ?', [
                strtoupper(trim($row->pan_number)),
            ])->first();
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Match by Mobile Number
        |--------------------------------------------------------------------------
        */

        if (! $customer && ! empty($row->mobile_no)) {
            $mobile = preg_replace('/[^0-9]/', '', $row->mobile_no);

            $customer = Customer::where('mobile_no', $mobile)->first();
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Match by Customer Name (Last Fallback)
        |--------------------------------------------------------------------------
        */

        if (! $customer && ! empty($row->customer_name)) {

            $customers = Customer::whereRaw(
                'LOWER(customer_name) = ?',
                [strtolower(trim($row->customer_name))]
            )->get();

            if ($customers->count() === 1) {
                $customer = $customers->first();
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Update MIS Row
        |--------------------------------------------------------------------------
        */

        if ($customer) {

            $row->update([
                'customer_id'   => $customer->id,
                'match_status'  => 'matched',
                'match_remarks' => 'Matched Successfully',
            ]);

            return $customer;
        }

        $row->update([
            'match_status'  => 'unmatched',
            'match_remarks' => 'Customer not found',
        ]);

        return null;
    }
}
