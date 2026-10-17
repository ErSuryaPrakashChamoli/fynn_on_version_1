<?php

namespace App\Services\Cibil;

use RuntimeException;

/**
 * Raised by a CibilClient when the bureau cannot return a usable answer. The
 * message is shown to the user and kept on the cibil_reports row, so it must
 * be readable and must never carry credentials, a full PAN or mobile number,
 * report data or a signed link.
 *
 * $outcomeUncertain marks the cases where the provider may still have
 * processed (and billed) the request — a timeout, a gateway error, an
 * unreadable success response. Those rows are kept as "outcome uncertain"
 * and are never retried automatically.
 */
class CibilApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $outcomeUncertain = false,
        public readonly ?int $providerStatusCode = null,
        public readonly ?string $providerMessageCode = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(string $bureau = 'CIBIL'): self
    {
        return new self("The {$bureau} API is not connected yet. Please contact the Admin.");
    }

    public static function credentialsMissing(string $bureau = 'CIBIL'): self
    {
        return new self("{$bureau} credentials are not configured. Please contact the Admin.");
    }

    public static function rejected(int $status, ?string $providerMessage = null, ?string $messageCode = null): self
    {
        return new self(
            trim('The provider rejected the request.'.($providerMessage ? " {$providerMessage}" : '')),
            providerStatusCode: $status,
            providerMessageCode: $messageCode,
        );
    }

    public static function invalidCredentials(int $status, string $bureau = 'CIBIL'): self
    {
        return new self("The provider did not accept the {$bureau} credentials. Please ask the Admin to check the Surepass token.", providerStatusCode: $status);
    }

    public static function notPermitted(int $status, string $bureau = 'CIBIL'): self
    {
        return new self("The provider account is not permitted to use this {$bureau} service. Please contact the Admin.", providerStatusCode: $status);
    }

    public static function rateLimited(int $status): self
    {
        return new self('The provider is limiting requests right now. Please wait a few minutes and try again.', providerStatusCode: $status);
    }

    public static function unavailable(?int $status = null, string $bureau = 'CIBIL'): self
    {
        return new self("The {$bureau} service is temporarily unavailable. Please try again later.", providerStatusCode: $status);
    }

    public static function uncertain(?int $status = null, ?string $messageCode = null): self
    {
        return new self(
            'The request outcome is uncertain; the provider may have processed it. Verify its status before retrying.',
            outcomeUncertain: true,
            providerStatusCode: $status,
            providerMessageCode: $messageCode,
        );
    }

    public static function missingReport(int $status, ?string $messageCode = null): self
    {
        return new self('The provider returned no credit report for this applicant.', providerStatusCode: $status, providerMessageCode: $messageCode);
    }

    public static function missingPdf(int $status, ?string $messageCode = null): self
    {
        return new self('The provider did not return a PDF report.', providerStatusCode: $status, providerMessageCode: $messageCode);
    }
}
