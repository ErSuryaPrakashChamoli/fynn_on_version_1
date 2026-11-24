<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\Leads\LeadResource;
use App\Support\LeadListingPeriod;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListLeads extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = LeadResource::class;

    /**
     * The status picked beside "Export Leads" ("all", "other" or a
     * follow-up status); the listing and its export both follow it,
     * together with the topbar period (LeadListingPeriod).
     */
    #[Url(as: 'status')]
    public string $leadStatus = LeadListingPeriod::ALL_STATUSES;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
