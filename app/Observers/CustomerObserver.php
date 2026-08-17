<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\Settlement\SettlementService;

class CustomerObserver
{
    public function updated(Customer $customer): void
    {
        /*
         * Only create settlement when the application
         * becomes finally disbursed.
         */
        if (
            $customer->wasChanged('disbursal_finalized')
            && $customer->disbursal_finalized
        ) {
            app(SettlementService::class)
                ->createSalesSnapshot($customer);
        }
    }
}
