<?php

namespace App\Filament\Resources\CibilReports\Pages;

use App\Enums\CibilReportStatus;
use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Filament\Resources\CibilReports\Schemas\CibilReportForm;
use App\Models\CibilReport;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Lead;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\BureauCheckCandidates;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCibilReport extends CreateRecord
{
    protected static string $resource = CibilReportResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Fetch CIBIL report';
    }

    /** Pre-fills the file when opened from a customer page (?customer=ID). */
    protected function afterFill(): void
    {
        $customer = BureauCheckCandidates::resolveCustomer(request()->query('customer'));

        if ($customer instanceof Customer) {
            $this->form->fill([
                'fetch_for' => CibilReportForm::FOR_CUSTOMER,
                'customer_id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'pan_number' => strtoupper((string) $customer->pan_number),
                'mobile_no' => $customer->mobile_no,
                'email' => $customer->email,
            ]);
        }
    }

    /**
     * Every report is for a lead or a customer file, resolved through the
     * owning listing's own scope, so a crafted post cannot attach a report to
     * somebody else's lead or file.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $subject = $this->resolveSubject($data);

        try {
            return app(CibilReportService::class)->fetch($user, $data, $subject);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])
                ->all());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveSubject(array $data): Lead|CustomerAssignment|Customer
    {
        return match ($data['fetch_for'] ?? null) {
            CibilReportForm::FOR_LEAD => BureauCheckCandidates::resolveLead($data['lead_key'] ?? null)
                ?? throw ValidationException::withMessages(['data.lead_key' => 'Pick a lead marked "Bureau Check" that you can see.']),
            CibilReportForm::FOR_CUSTOMER => BureauCheckCandidates::resolveCustomer($data['customer_id'] ?? null)
                ?? throw ValidationException::withMessages(['data.customer_id' => 'Pick a customer file you can see.']),
            default => throw ValidationException::withMessages(['data.fetch_for' => 'Choose whether the report is for a lead or a customer.']),
        };
    }

    protected function getCreatedNotification(): ?Notification
    {
        /** @var CibilReport $report */
        $report = $this->record;

        if ($report->status === CibilReportStatus::Success) {
            $score = $report->score !== null ? "Score: {$report->score}." : null;

            return Notification::make()
                ->success()
                ->title('CIBIL report fetched')
                ->body($report->isForLead() ? trim($score.' Now mark the lead Eligible or Not Eligible.') : $score);
        }

        return Notification::make()
            ->danger()
            ->title('CIBIL report could not be fetched')
            ->body($report->error_message);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
