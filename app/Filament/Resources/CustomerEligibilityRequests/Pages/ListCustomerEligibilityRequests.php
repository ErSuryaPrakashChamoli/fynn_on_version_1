<?php

namespace App\Filament\Resources\CustomerEligibilityRequests\Pages;

use App\Filament\Resources\CustomerEligibilityRequests\CustomerEligibilityRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomerEligibilityRequests extends ListRecords
{
    protected static string $resource = CustomerEligibilityRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Eligibility Request')
                ->visible(fn (): bool => CustomerEligibilityRequestResource::canCreate()),
        ];
    }
}
