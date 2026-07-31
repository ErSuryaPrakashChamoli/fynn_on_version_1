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

        // if (
        //     $employee?->designation === Employee::DESIGNATION_CALLER ||
        //     $this->record->documents_submitted
        // ) {
        //     $this->redirect(CustomerResource::getUrl('view', [
        //         'record' => $this->record,
        //     ]));
        // }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }


    protected function mutateFormDataBeforeSave(array $data): array
    {

        // dd($data);
        // $oldStatus = strtolower($this->getRecord()->journey_status ?? 'sfl');
        // $currentStatus = $oldStatus;

        // $oldStatus = strtolower(
        //     $data['journey_status'] ?? $this->getRecord()->journey_status ?? 'sfl'
        // );
        // $currentStatus = $oldStatus;

        $oldStatus = strtolower($this->getRecord()->journey_status ?? 'sfl');
        $currentStatus = $oldStatus;

        /**
         * ==========================================
         * STAGE 1 : SFL
         * ==========================================
         */
        if ($currentStatus === 'sfl') {

            switch ($data['documentation_status'] ?? null) {

                case 'pending':
                    $data['journey_status'] = 'sfl';
                    $data['underwriting_status'] = null;
                    break;

                case 'complete':
                    $data['journey_status'] = 'underwriting';

                    // Default only if user hasn't selected anything
                    if (blank($data['underwriting_status'])) {
                        $data['underwriting_status'] = 'in_process';
                    }
                    break;
            }
        }

        /**
         * ==========================================
         * STAGE 2 : UNDERWRITING
         * ==========================================
         */
        if ($currentStatus === 'underwriting') {

            switch ($data['underwriting_status'] ?? null) {

                case 'in_process':
                    $data['journey_status'] = 'underwriting';
                    break;

                case 'approved':
                    $data['journey_status'] = 'approved';
                    break;

                case 'rejected':
                    $data['journey_status'] = 'not_approved';
                    break;
            }
        }



        if (! empty($data['disbursal_status'] )) {

            switch ($data['disbursal_status']) {

                case 'disbursed':
                    $data['journey_status'] = 'sanctioned';
                    $data['disbursal_finalized'] = true;
                    $data['disbursal_status'] = 'disbursed';

                    break;

                case 'carry_forward':
                    $data['journey_status'] = 'carry_forward';
                    $data['disbursal_finalized'] = false;
                    $data['disbursal_status'] = 'carry_forward';
                    break;

                case 'dropped':
                    $data['journey_status'] = 'dropped';
                    $data['disbursal_finalized'] = true;
                    $data['disbursal_status'] = 'dropped';
                    break;
            }
        }

        /**
         * ==========================================
         * STAGE 5 : DISBURSAL DOCUMENTS
         * ==========================================
         */
        if (
            $currentStatus === 'disbursal_documents'
            && ($data['documents_submitted'] ?? false)
        ) {
            $data['journey_status'] = 'sanctioned';
        }

        /**
         * ==========================================
         * STAGE HISTORY
         * ==========================================
         */
        // $newStatus = strtolower($data['journey_status'] ?? $oldStatus);

        // if ($oldStatus !== $newStatus) {

        //     CustomerStageHistory::create([
        //         'customer_id'  => $this->getRecord()->id,
        //         'stage_name'   => ucfirst($oldStatus) . ' Stage',
        //         'status_value' => 'Moved to ' . ucfirst(str_replace('_', ' ', $newStatus)),
        //         'user_id'      => auth()->id(),
        //     ]);
        // }

        /**
         * ==========================================
         * DOCUMENT SUBMISSION FIX
         * ==========================================
         */
        // if (session()->has("customer_{$this->getRecord()->id}_docs_submitted")) {
        //     $data['documents_submitted'] = true;
        // }

        // if (
        //     $currentStatus === 'disbursal_documents'
        //     && ($data['documents_submitted'] ?? false)
        // ) {
        //     $data['journey_status'] = 'sanctioned';
        // }

        return $data;
    }
}
