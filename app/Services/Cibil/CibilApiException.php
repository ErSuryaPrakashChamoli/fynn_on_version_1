<?php

namespace App\Services\Cibil;

use RuntimeException;

/**
 * Raised by a CibilClient when the bureau cannot return a report. The
 * message is shown to the user and kept on the failed cibil_reports row, so
 * it must be readable and must never carry credentials.
 */
class CibilApiException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('The CIBIL API is not connected yet. Please contact the Admin.');
    }
}
