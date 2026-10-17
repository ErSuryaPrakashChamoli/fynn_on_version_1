<?php

namespace App\Services\Cibil;

/**
 * Bound while no bureau API is connected (CIBIL_DRIVER unset). Every pull is
 * recorded as Failed with a clear reason instead of pretending to succeed.
 */
class UnconfiguredCibilClient implements CibilClient
{
    public function fetch(CibilApplicant $applicant): CibilFetchResult
    {
        throw CibilApiException::notConfigured();
    }
}
