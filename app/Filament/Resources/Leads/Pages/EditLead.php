<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Models\CustomerPanRequest;
use App\Services\PanDuplicateService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // DeleteAction::make(),
        ];
    }

    /**
     * "Continue" on an approved duplicate-PAN request raised from this
     * lead lands here with ?pan_request=: the approved PAN is filled in.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $requestId = request()->integer('pan_request');

        if (! $requestId) {
            return;
        }

        $request = CustomerPanRequest::query()->find($requestId);

        abort_unless(
            $request
                && $request->isForLead()
                && $request->status === CustomerPanRequest::STATUS_APPROVED
                && $request->requested_by === auth()->user()?->employee?->id
                && (int) $request->lead_id === (int) $this->record->getKey(),
            403,
        );

        if ($request->isUsed()) {
            LeadForm::notifyApprovalAlreadyUsed();

            return;
        }

        $this->data['pan_number'] = $request->pan_number;
        $this->data['pan_status'] = 'approved';
    }

    protected function afterSave(): void
    {
        if ($this->record->wasChanged('pan_number')) {
            app(PanDuplicateService::class)->spendLeadApproval(auth()->user()?->employee, $this->record, isNewLead: false);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
