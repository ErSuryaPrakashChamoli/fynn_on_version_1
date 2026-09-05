<?php

namespace App\Filament\Resources\AssignedLeads\Pages;

use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\FollowUp;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditAssignedLead extends EditRecord
{
    protected static string $resource = AssignedLeadResource::class;

    protected array $pendingFollowUpData = [];

    protected array $pendingProspectData = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('convertToCustomer')
                ->label('Convert to Customer')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->isEligibleForConversion())
                ->url(fn () => CustomerResource::getUrl('create', [
                    'ai_customer_record' => $this->record->ai_customer_record_id,
                ])),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingFollowUpData = [
            'follow_up_type' => $data['follow_up_type'],
            'status' => $data['status'],
            'bank_id' => $data['bank_id'] ?? null,
            'next_follow_up_date' => $data['next_follow_up_date'] ?? null,
            'remarks' => $data['remarks'],
        ];

        // Prospect fields (pan_number, email, locations, salary) are only
        // dehydrated when the assignment isn't linked to a real Customer yet
        // — see AssignedLeadForm::isLockedToCustomer(). Collect whichever of
        // them are present so afterSave() can persist them onto the source
        // AI-extracted record, keeping the eventual Customer conversion form
        // pre-filled with what was captured here.
        $this->pendingProspectData = collect($data)
            ->only(['pan_number', 'email', 'current_location', 'job_location', 'residence_location', 'salary'])
            ->filter(fn ($value) => filled($value))
            ->all();

        // The assigned lead record itself owns none of these fields — they
        // belong to a new dated FollowUp entry and/or the linked AI record,
        // both handled in afterSave(), so nothing here needs to be persisted
        // via the assignment's own update().
        return [];
    }

    protected function afterSave(): void
    {
        FollowUp::create([
            'customer_id' => $this->record->customer_id,
            'ai_customer_record_id' => $this->record->ai_customer_record_id,
            'employee_id' => auth()->user()?->employee?->id,
            ...$this->pendingFollowUpData,
        ]);

        if (filled($this->pendingProspectData) && blank($this->record->customer_id) && $this->record->ai_customer_record_id) {
            $aiRecord = $this->record->aiCustomerRecord;

            if ($aiRecord) {
                $aiRecord->update([
                    'data' => array_merge($aiRecord->data ?? [], $this->pendingProspectData),
                ]);
            }
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
