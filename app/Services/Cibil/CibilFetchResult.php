<?php

namespace App\Services\Cibil;

/**
 * What a successful bureau call hands back: the score (null when the bureau
 * has none for the applicant), the bureau's own reference for the request
 * (Surepass client_id), the answer as received (stored encrypted, never with
 * a PDF link or file in it), and — for a PDF request — the PDF as a signed
 * link and/or inline base64, which the service saves to private storage
 * straight away.
 */
final readonly class CibilFetchResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ?int $score,
        public array $payload,
        public ?string $bureauReference = null,
        public ?string $pdfLink = null,
        public ?string $pdfBase64 = null,
        public ?int $providerStatusCode = null,
        public ?string $providerMessageCode = null,
    ) {}
}
