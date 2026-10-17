<?php

namespace App\Services\Cibil;

use App\Enums\CibilReportStatus;
use App\Models\CibilReport;

/**
 * The answer to a CIBIL request: either the new request row (fetched,
 * failed or uncertain), or an earlier report that already answers it and
 * was reused instead of paying the bureau again.
 */
final readonly class CibilRequestOutcome
{
    public function __construct(
        public CibilReport $report,
        public bool $reused = false,
    ) {}

    public function succeeded(): bool
    {
        return $this->report->status === CibilReportStatus::Success;
    }
}
