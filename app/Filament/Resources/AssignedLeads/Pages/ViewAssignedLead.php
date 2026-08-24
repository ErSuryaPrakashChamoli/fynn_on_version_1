<?php

namespace App\Filament\Resources\AssignedLeads\Pages;

use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAssignedLead extends ViewRecord
{
    protected static string $resource = AssignedLeadResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->recordOpen();
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Follow Up'),

            Action::make('convertToCustomer')
                ->label('Convert')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn() => $this->record->isEligibleForConversion())
                ->url(fn() => CustomerResource::getUrl('create', [
                    'ai_customer_record' => $this->record->ai_customer_record_id,
                ])),
        ];
    }
}
