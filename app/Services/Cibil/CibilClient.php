<?php

namespace App\Services\Cibil;

/**
 * The one call the app makes to the credit bureau. Which implementation is
 * bound is chosen by config('cibil.driver') in AppServiceProvider; the
 * real HTTP client is added here once the bureau's API details are in.
 */
interface CibilClient
{
    /**
     * @throws CibilApiException when the bureau cannot return a report
     */
    public function fetch(CibilApplicant $applicant): CibilFetchResult;
}
