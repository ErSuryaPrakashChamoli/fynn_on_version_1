<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Models\CustomerPanRequest;
use App\Services\PanDuplicateService;
use App\Support\PanMatch;
use Filament\Resources\Pages\CreateRecord;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    /**
     * "Continue" on an approved duplicate-PAN request lands here with
     * ?pan_request=: the approved PAN (and the matched person's name and
     * mobile) are filled in and the lead can be saved with it.
     */
    public function mount(): void
    {
        parent::mount();

        $request = $this->approvedPanRequestFromQuery();

        if (! $request) {
            return;
        }

        $match = app(PanDuplicateService::class)->firstMatch($request->pan_number);

        $this->form->fill([
            'pan_number' => $request->pan_number,
            'customer_name' => $match?->name,
            'mobile_no' => $match?->mobile,
        ]);

        $this->data['pan_status'] = $match instanceof PanMatch ? 'approved' : 'available';
    }

    protected function afterCreate(): void
    {
        app(PanDuplicateService::class)->spendLeadApproval(auth()->user()?->employee, $this->record, isNewLead: true);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    private function approvedPanRequestFromQuery(): ?CustomerPanRequest
    {
        $requestId = request()->integer('pan_request');

        if (! $requestId) {
            return null;
        }

        $request = CustomerPanRequest::query()->find($requestId);

        abort_unless(
            $request
                && $request->isForLead()
                && $request->status === CustomerPanRequest::STATUS_APPROVED
                && $request->requested_by === auth()->user()?->employee?->id
                && blank($request->lead_id),
            403,
        );

        if ($request->isUsed()) {
            LeadForm::notifyApprovalAlreadyUsed();

            return null;
        }

        return $request;
    }
}
