<?php

namespace App\Services\Cibil;

/**
 * What a successful bureau call hands back: the score, the bureau's own
 * reference for the pull, and the full report exactly as received (stored
 * encrypted and rendered on the report's view page).
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
    ) {}
}
