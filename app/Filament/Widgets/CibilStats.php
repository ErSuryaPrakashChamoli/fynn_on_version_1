<?php

namespace App\Filament\Widgets;

use App\Enums\CibilFailureCategory;
use App\Enums\CibilPdfStatus;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Models\CibilReport;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use App\Support\CibilDashboardFilters;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Headline numbers for the CIBIL Dashboard, counted from the cibil_reports
 * rows the viewer can see (all of them for the Admin), narrowed by the
 * dashboard's filters — a read model only. Rates are over completed
 * requests; provider time is the average of the recorded durations. There
 * is no cost figure: no pricing is configured, and none is guessed.
 */
class CibilStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

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
        $filtered = fn (): Builder => CibilDashboardFilters::apply($visible(), $this->pageFilters);

        /** @var array<string, int> $counts keyed "type|status" */
        $counts = $filtered()
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

        $total = $count();
        $succeeded = $count(status: CibilReportStatus::Success);
        $failed = $count(status: CibilReportStatus::Failed);
        $uncertain = $count(status: CibilReportStatus::Uncertain);
        $completed = $succeeded + $failed + $uncertain;
        $rate = fn (int $part): string => $completed === 0 ? '—' : round($part / $completed * 100, 1).'%';

        $timedOut = $filtered()->where('failure_category', CibilFailureCategory::Timeout)->count();
        $unverified = $filtered()->where('status', CibilReportStatus::Uncertain)->whereNull('resolved_at')->count();
        $pdfAvailable = $filtered()->where('pdf_status', CibilPdfStatus::Available)->count();
        $averageMs = $filtered()->whereNotNull('duration_ms')->avg('duration_ms');
        $customers = $filtered()->whereNotNull('customer_id')->distinct()->count('customer_id');
        $leads = $filtered()->whereNotNull('lead_id')->distinct()->count('lead_id')
            + $filtered()->whereNotNull('customer_assignment_id')->distinct()->count('customer_assignment_id');

        $bureau = CreditBureau::tryFrom((string) ($this->pageFilters['bureau'] ?? ''));
        $todayAndMonth = fn (string $period): int => CibilDashboardFilters::apply($visible(), ['period' => $period, 'bureau' => $bureau?->value])->count();

        [$from, $until] = CibilDashboardFilters::range($this->pageFilters);
        $reused = Activity::query()
            ->where('log_name', CibilReportService::LOG_NAME)
            ->where('event', 'reused')
            ->when(! CibilAccess::canManage($user), fn (Builder $query): Builder => $query->where('causer_id', $user->getKey())->where('causer_type', $user->getMorphClass()))
            ->when($from, fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($until, fn (Builder $query) => $query->where('created_at', '<=', $until))
            ->when($bureau, fn (Builder $query, CreditBureau $bureau): Builder => $query->where('properties->bureau', $bureau->value))
            ->count();

        return [
            Stat::make('Total requests', $total)
                ->description($todayAndMonth('today').' today · '.$todayAndMonth('this_month').' this month')
                ->icon('heroicon-o-queue-list'),

            Stat::make('Succeeded', $succeeded)
                ->description('Success rate '.$rate($succeeded))
                ->icon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Failed requests', $failed)
                ->description('Failure rate '.$rate($failed))
                ->icon('heroicon-o-x-circle')
                ->color('danger'),

            Stat::make('Outcome uncertain', $uncertain)
                ->description("{$unverified} awaiting verification · {$timedOut} timed out")
                ->icon('heroicon-o-question-mark-circle')
                ->color('warning'),

            Stat::make('Pending requests', $count(status: CibilReportStatus::Pending))
                ->description('Waiting for the provider')
                ->icon('heroicon-o-clock')
                ->color('info'),

            Stat::make('Score checks fetched', $count(CibilRequestType::Score, CibilReportStatus::Success))
                ->description($count(CibilRequestType::Score).' requested')
                ->icon('heroicon-o-chart-bar')
                ->color('info'),

            Stat::make('Full reports fetched', $count(CibilRequestType::FullReport, CibilReportStatus::Success))
                ->description($count(CibilRequestType::FullReport).' requested')
                ->icon('heroicon-o-document-text')
                ->color('primary'),

            Stat::make('PDF requests', $count(CibilRequestType::Pdf))
                ->description("{$pdfAvailable} PDF(s) saved")
                ->icon('heroicon-o-document-arrow-down'),

            Stat::make('Average provider time', $averageMs === null ? '—' : (round((float) $averageMs / 1000, 1).' s'))
                ->description('Over requests with a recorded duration')
                ->icon('heroicon-o-bolt'),

            Stat::make('Files checked', $customers + $leads)
                ->description("{$customers} customer(s) · {$leads} lead(s)")
                ->icon('heroicon-o-users'),

            Stat::make('Reports reused', $reused)
                ->description('Requests answered from a report on file — no new provider call')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('success'),

            Stat::make('Estimated cost', 'Not configured')
                ->description('No provider pricing is set up, so no cost is shown')
                ->icon('heroicon-o-currency-rupee')
                ->color('gray'),
        ];
    }
}
