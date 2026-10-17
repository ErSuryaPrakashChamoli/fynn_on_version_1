<?php

namespace App\Filament\Resources\CibilRequests\Pages;

use App\Enums\CibilReportStatus;
use App\Filament\Resources\CibilRequests\CibilRequestResource;
use App\Services\Cibil\CibilReportService;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCibilRequests extends ListRecords
{
    protected static string $resource = CibilRequestResource::class;

    /** Requests the app gave up waiting for are shown as uncertain, not as still pending. */
    public function mount(): void
    {
        app(CibilReportService::class)->expireStalePending();

        parent::mount();
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            CibilRequestResource::FAILED_TAB => Tab::make('Failed & uncertain')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [CibilReportStatus::Failed, CibilReportStatus::Uncertain]))
                ->badge(fn (): int => $this->countWithStatus([CibilReportStatus::Failed, CibilReportStatus::Uncertain]))
                ->badgeColor('danger'),

            'uncertain' => Tab::make('Outcome uncertain')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', CibilReportStatus::Uncertain)->whereNull('resolved_at'))
                ->badge(fn (): int => CibilRequestResource::getEloquentQuery()->where('status', CibilReportStatus::Uncertain)->whereNull('resolved_at')->count())
                ->badgeColor('warning'),

            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', CibilReportStatus::Pending))
                ->badge(fn (): int => $this->countWithStatus([CibilReportStatus::Pending])),
        ];
    }

    /**
     * @param  list<CibilReportStatus>  $statuses
     */
    protected function countWithStatus(array $statuses): int
    {
        return CibilRequestResource::getEloquentQuery()->whereIn('status', $statuses)->count();
    }
}
