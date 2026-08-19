<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use App\Models\CustomerStageHistory;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Models\Employee;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            // DeleteAction::make(),
        ];
    }

    public function mount($record): void
    {
        parent::mount($record);

        $employee = auth()->user()->employee;

        if (
            $employee?->designation === Employee::DESIGNATION_CALLER
        ) {
            $this->redirect(CustomerResource::getUrl('view', [
                'record' => $this->record,
            ]));
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
