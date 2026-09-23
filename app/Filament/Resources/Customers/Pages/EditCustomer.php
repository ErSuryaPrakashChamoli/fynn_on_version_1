<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Employee;
use App\Services\CustomerEligibilityService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

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

    /**
     * journey_status is a disabled-but-dehydrated field, so a page opened
     * before an eligibility change would write the stale stage back on save
     * (e.g. leaving a now-Eligible file at not_started, out of SFL). The
     * stage the eligibility implies always wins over the stale form value.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $isEligible = $this->record->eligibility_status === CustomerEligibilityService::ELIGIBLE;
        $submitted = $data['journey_status'] ?? null;

        if ($isEligible && $submitted === 'not_started') {
            $data['journey_status'] = $this->record->journey_status;
        }

        if (! $isEligible && $submitted !== 'not_started') {
            $data['journey_status'] = $this->record->journey_status;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
