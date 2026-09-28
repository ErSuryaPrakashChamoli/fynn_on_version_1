<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\CustomerEditRequests\CustomerEditRequestResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Services\CustomerEditRequestService;
use Filament\Actions\Action;
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

        // Ask the Admin to change any journey field, with a reason; approval
        // writes it onto this file (Request → Customer Edit Requests).
        if (app(CustomerEditRequestService::class)->canRaiseOn(auth()->user(), $this->record)) {
            $actions[] = Action::make('requestEdit')
                ->label('Request edit')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->url(CustomerEditRequestResource::getUrl('create', ['customer' => $this->record->getKey()]));
        }

        return $actions;
    }
}
