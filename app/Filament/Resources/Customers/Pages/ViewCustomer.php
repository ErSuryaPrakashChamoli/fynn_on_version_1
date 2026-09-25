<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [];

        // canEdit() lets a Caller through only as a continuity backup or takeover holder.
        if (
            CustomerResource::canEdit($this->record) &&
            ! $this->record->documents_submitted
        ) {
            $actions[] = EditAction::make();
        }

        return $actions;
    }
}
