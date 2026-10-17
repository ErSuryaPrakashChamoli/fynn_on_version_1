<?php

namespace App\Services\Cibil;

use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;

/**
 * Bound while no bureau API is connected (CIBIL_DRIVER unset). Every request
 * is recorded as Failed with a clear reason instead of pretending to succeed.
 */
class UnconfiguredCibilClient implements CibilClient
{
    public function fetch(CibilApplicant $applicant, CibilRequestType $type, CreditBureau $bureau = CreditBureau::Cibil): CibilFetchResult
    {
        throw CibilApiException::notConfigured($bureau->label());
    }
}
