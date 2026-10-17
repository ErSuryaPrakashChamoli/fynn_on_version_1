<?php

namespace App\Services\Cibil;

use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;

/**
 * The one call the app makes to the credit bureau. Which implementation is
 * bound is chosen by config('cibil.driver') in AppServiceProvider: the live
 * Surepass client, the fake for development and /demo, or the unconfigured
 * stand-in that refuses every request.
 */
interface CibilClient
{
    /**
     * Asks $bureau (CIBIL or Experian) for $type about the applicant.
     *
     * @throws CibilApiException when the bureau cannot return a usable answer
     */
    public function fetch(CibilApplicant $applicant, CibilRequestType $type, CreditBureau $bureau = CreditBureau::Cibil): CibilFetchResult;
}
