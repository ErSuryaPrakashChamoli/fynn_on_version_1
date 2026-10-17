<?php

namespace App\Services\Cibil;

use App\Enums\CibilPdfStatus;

/**
 * What became of a PDF the provider sent: saved (with its private path), or
 * why not — and whether the signed link is still worth one more try.
 */
final readonly class CibilStoredPdf
{
    public function __construct(
        public CibilPdfStatus $status,
        public ?string $path = null,
        public bool $keepLink = false,
    ) {}
}
