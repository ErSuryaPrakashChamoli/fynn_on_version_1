<?php

namespace App\Filament\Widgets;

use App\Enums\CibilReportStatus;
use App\Models\CibilReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ten newest CIBIL requests that failed or ended uncertain, on the
 * CIBIL Dashboard.
 */
class CibilRecentFailures extends CibilRecentRequests
{
    protected static ?string $heading = 'Recent failures';

    /**
     * @param  Builder<CibilReport>  $query
     * @return Builder<CibilReport>
     */
    protected function narrow(Builder $query): Builder
    {
        return $query->whereIn('status', [CibilReportStatus::Failed, CibilReportStatus::Uncertain]);
    }
}
