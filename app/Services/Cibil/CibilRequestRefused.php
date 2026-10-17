<?php

namespace App\Services\Cibil;

use App\Models\CibilReport;
use RuntimeException;

/**
 * A request the app will not send to the bureau right now — switched off
 * (requests or the request type),
 * another one still in flight, or an earlier one whose outcome is uncertain.
 * Nothing is recorded or billed; the message is shown to the user as is.
 */
class CibilRequestRefused extends RuntimeException
{
    public function __construct(string $message, public readonly ?CibilReport $blockingReport = null)
    {
        parent::__construct($message);
    }

    public static function switchedOff(): self
    {
        return new self('CIBIL requests are switched off. Ask the Admin to enable them in CIBIL Settings.');
    }

    public static function typeSwitchedOff(string $label): self
    {
        return new self("{$label} requests are switched off in CIBIL Settings.");
    }

    public static function freshSwitchedOff(): self
    {
        return new self('Fresh CIBIL requests are switched off in CIBIL Settings, so the existing report has to be used.');
    }

    public static function inProgress(?CibilReport $pending = null): self
    {
        return new self('A previous request is still processing. Please wait for it to finish before trying again.', $pending);
    }

    public static function uncertain(CibilReport $uncertain): self
    {
        return new self('The request outcome is uncertain; verify its status before retrying.', $uncertain);
    }
}
