<?php

namespace App\Filament\Widgets;

use App\Enums\CibilPdfStatus;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Models\CibilReport;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Headline numbers for the CIBIL Dashboard, counted from the cibil_reports
 * rows the viewer can see (all of them for the Admin) — a read model only.
 */
class CibilStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return CibilAccess::canUse(Filament::auth()->user());
    }

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $visible = fn (): Builder => app(CibilReportService::class)->scopeVisible(CibilReport::query(), $user);

        /** @var array<string, int> $counts keyed "type|status" */
        $counts = $visible()
            ->toBase()
            ->selectRaw('request_type, status, COUNT(*) as aggregate')
            ->groupBy('request_type', 'status')
            ->get()
            ->mapWithKeys(fn (object $row): array => ["{$row->request_type}|{$row->status}" => (int) $row->aggregate])
            ->all();

        $count = fn (?CibilRequestType $type = null, ?CibilReportStatus $status = null): int => collect($counts)
            ->filter(function (int $aggregate, string $key) use ($type, $status): bool {
                [$rowType, $rowStatus] = explode('|', $key);

                return ($type === null || $rowType === $type->value) && ($status === null || $rowStatus === $status->value);
            })
            ->sum();

        $pdfAvailable = $visible()->where('pdf_status', CibilPdfStatus::Available)->count();
        $unverified = $visible()->where('status', CibilReportStatus::Uncertain)->whereNull('resolved_at')->count();

        $reused = Activity::query()
            ->where('log_name', CibilReportService::LOG_NAME)
            ->where('event', 'reused')
            ->when(! CibilAccess::canManage($user), fn (Builder $query): Builder => $query->where('causer_id', $user->getKey())->where('causer_type', $user->getMorphClass()))
            ->count();

        return [
            Stat::make('Total requests', $count())
                ->description('Score, full report and PDF requests')
                ->icon('heroicon-o-queue-list'),

            Stat::make('Score checks fetched', $count(CibilRequestType::Score, CibilReportStatus::Success))
                ->icon('heroicon-o-chart-bar')
                ->color('info'),

            Stat::make('Full reports fetched', $count(CibilRequestType::FullReport, CibilReportStatus::Success))
                ->icon('heroicon-o-document-text')
                ->color('primary'),

            Stat::make('PDF requests', $count(CibilRequestType::Pdf))
                ->description("{$pdfAvailable} PDF(s) saved")
                ->icon('heroicon-o-document-arrow-down'),

            Stat::make('Failed requests', $count(status: CibilReportStatus::Failed))
                ->icon('heroicon-o-x-circle')
                ->color('danger'),

            Stat::make('Pending requests', $count(status: CibilReportStatus::Pending))
                ->description('Waiting for the provider')
                ->icon('heroicon-o-clock')
                ->color('info'),

            Stat::make('Outcome uncertain', $count(status: CibilReportStatus::Uncertain))
                ->description("{$unverified} awaiting verification")
                ->icon('heroicon-o-question-mark-circle')
                ->color('warning'),

            Stat::make('Reports reused', $reused)
                ->description('Requests answered from a report on file — not billed')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('success'),
        ];
    }
}
