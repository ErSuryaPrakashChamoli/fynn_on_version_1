<?php

namespace App\Filament\Resources\CibilReports\Pages;

use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Filament\Resources\CibilReports\Schemas\CibilReportForm;
use App\Models\CibilReport;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Lead;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Services\Cibil\CibilRequestRefused;
use App\Support\BureauCheckCandidates;
use App\Support\CibilMask;
use App\Support\CibilSubject;
use Filament\Actions\Action;
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

    /** The file already had a report that answers the request, so it was opened instead. */
    protected bool $reused = false;

    public function getTitle(): string
    {
        return 'Fetch bureau report';
    }

    /** Pre-fills the file when opened from a customer page (?customer=ID). */
    protected function afterFill(): void
    {
        $customer = BureauCheckCandidates::resolveCustomer(request()->query('customer'));

        if ($customer instanceof Customer) {
            $this->form->fill([
                ...$this->form->getRawState(),
                ...CibilSubject::details($customer),
                'fetch_for' => CibilReportForm::FOR_CUSTOMER,
                'customer_id' => $customer->id,
            ]);
        }
    }

    /**
     * Every request is for a lead or a customer file, resolved through the
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

        $bureau = CreditBureau::tryFrom((string) ($data['bureau'] ?? ''))
            ?? throw ValidationException::withMessages(['data.bureau' => 'Choose the bureau to request from.']);
        $subject = $this->resolveSubject($data);
        $type = CibilRequestType::tryFrom((string) ($data['request_type'] ?? ''))
            ?? throw ValidationException::withMessages(['data.request_type' => 'Choose what to request.']);

        try {
            $outcome = app(CibilReportService::class)->request($user, $type, $data, $subject, bureau: $bureau);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (CibilRequestRefused $exception) {
            Notification::make()
                ->warning()
                ->title("{$bureau->label()} request not sent")
                ->body($exception->getMessage())
                ->persistent()
                ->send();

            $this->halt();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])
                ->all());
        }

        $this->reused = $outcome->reused;

        return $outcome->report;
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
        $bureau = $report->bureau->label();

        if ($this->reused) {
            return Notification::make()
                ->info()
                ->title('Existing report opened')
                ->body("This file already has a {$bureau} {$report->request_type->label()} from {$report->fetched_at?->format('d M Y')}, so no new request was sent to {$bureau}.");
        }

        if ($report->status === CibilReportStatus::Uncertain) {
            return Notification::make()
                ->warning()
                ->title("{$bureau} request outcome uncertain")
                ->body($report->error_message)
                ->persistent();
        }

        if ($report->status === CibilReportStatus::Success) {
            $score = $report->score !== null ? "Score: {$report->score}." : null;

            return Notification::make()
                ->success()
                ->title("{$bureau} report fetched")
                ->body($report->isForLead() ? trim($score.' Now mark the lead Eligible or Not Eligible.') : $score);
        }

        return Notification::make()
            ->danger()
            ->title("{$bureau} report could not be fetched")
            ->body($report->error_message);
    }

    /**
     * The request may be billed, so it is confirmed first, showing what is
     * about to be sent — bureau, request, and the applicant with PAN and
     * mobile masked. Everything is validated again after confirming.
     */
    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Send request')
            ->submit(null)
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-paper-airplane')
            ->modalHeading(fn (): string => 'Send this request to '.(CreditBureau::tryFrom((string) ($this->data['bureau'] ?? ''))?->label() ?? 'the bureau').'?')
            ->modalDescription(fn (): string => $this->confirmationSummary())
            ->modalSubmitActionLabel('Send request')
            ->action(fn () => $this->create());
    }

    protected function confirmationSummary(): string
    {
        $bureau = CreditBureau::tryFrom((string) ($this->data['bureau'] ?? ''))?->label() ?? 'the bureau';
        $type = CibilRequestType::tryFrom((string) ($this->data['request_type'] ?? ''))?->label() ?? 'Request';
        $name = trim((string) ($this->data['customer_name'] ?? '')) ?: 'the applicant';
        $mobile = CibilMask::mobile($this->data['mobile_no'] ?? null) ?? '—';

        return "{$bureau} · {$type} for {$name} (PAN ".CibilMask::pan($this->data['pan_number'] ?? null).", mobile {$mobile}). "
            ."If this file already has a {$bureau} report that answers it, that report is opened and nothing is billed; otherwise the request is sent to {$bureau} and billed.";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
