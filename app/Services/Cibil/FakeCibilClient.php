<?php

namespace App\Services\Cibil;

use Illuminate\Support\Str;

/**
 * A stand-in bureau for local development and tests (CIBIL_DRIVER=fake).
 * The score is derived from the PAN so the same applicant always gets the
 * same answer; a PAN starting with "FAIL" (e.g. FAILX1234X) fails, to
 * exercise the error path. AppServiceProvider always binds it for /demo and
 * never binds it for the live app in production.
 */
class FakeCibilClient implements CibilClient
{
    public function fetch(CibilApplicant $applicant): CibilFetchResult
    {
        if (str_starts_with($applicant->panNumber, 'FAIL')) {
            throw new CibilApiException('The bureau could not find a record for this PAN.');
        }

        $score = 300 + (crc32($applicant->panNumber) % 601);

        return new CibilFetchResult(
            score: $score,
            bureauReference: 'FAKE-'.Str::upper(Str::random(10)),
            payload: [
                'source' => 'Fake bureau (development only)',
                'score' => $score,
                'name' => $applicant->name,
                'pan' => $applicant->panNumber,
                'accounts' => [
                    ['type' => 'Personal Loan', 'lender' => 'Sample Bank', 'status' => 'Active', 'overdue' => 0],
                    ['type' => 'Credit Card', 'lender' => 'Sample Card Co', 'status' => 'Closed', 'overdue' => 0],
                ],
                'enquiries_last_6_months' => 2,
            ],
        );
    }
}
