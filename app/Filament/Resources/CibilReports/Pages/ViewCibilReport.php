<?php

namespace App\Filament\Resources\CibilReports\Pages;

use App\Enums\BureauDecision;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Filament\Resources\CibilReports\Schemas\CibilRequestFields;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CibilReport;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Services\Cibil\CibilRequestRefused;
use App\Support\CibilAccess;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * @property CibilReport $record
 */
class ViewCibilReport extends ViewRecord
{
    protected static string $resource = CibilReportResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $user = Filament::auth()->user();

        if ($user instanceof User) {
            app(CibilReportService::class)->recordView($this->record, $user);
        }
    }

    public function getTitle(): string
    {
        return $this->record->bureau->label().' report — '.$this->record->customer_name;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->decisionAction(BureauDecision::Eligible),
            $this->decisionAction(BureauDecision::NotEligible),

            Action::make('convertToCustomer')
                ->label('Convert to customer')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->leadIsConvertible())
                ->requiresConfirmation()
                ->modalHeading('Convert Lead to Customer Profile?')
                ->modalDescription('This will check the PAN number for duplicates and take you to the Customer creation form. If the PAN already belongs to an existing customer, you will need to request admin approval before proceeding.')
                ->action(function (): void {
                    $this->convertLead();
                }),

            Action::make('openPdf')
                ->label('Open PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => $this->canDownloadPdf())
                ->url(fn (): ?string => $this->record->pdfUrl())
                ->openUrlInNewTab(),

            Action::make('retryPdfDownload')
                ->label('Retry PDF download')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => CibilAccess::canDownloadPdf(Filament::auth()->user()) && app(CibilReportService::class)->canRetryPdfDownload($this->record))
                ->requiresConfirmation()
                ->modalDescription('The provider\'s link is tried once more. No new request is sent to the bureau.')
                ->action(function (): void {
                    /** @var User $user */
                    $user = Filament::auth()->user();

                    $report = app(CibilReportService::class)->retryPdfDownload($user, $this->record);
                    $saved = $report->hasPdf();

                    Notification::make()
                        ->status($saved ? 'success' : 'warning')
                        ->title($saved ? 'PDF saved' : 'PDF still not available')
                        ->body($saved ? null : $report->pdf_status?->label())
                        ->send();
                }),

            Action::make('verifyUncertain')
                ->label('Mark as verified')
                ->icon('heroicon-o-shield-check')
                ->color('warning')
                ->visible(fn (): bool => $this->record->isUnresolvedUncertain() && CibilAccess::canManage(Filament::auth()->user()))
                ->modalHeading('Verify the uncertain request')
                ->modalDescription('Check the request against the provider (e.g. the Surepass dashboard) first. Once verified, a new request for this PAN can be sent.')
                ->schema([
                    Textarea::make('note')
                        ->label('What did you find?')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500)
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    /** @var User $user */
                    $user = Filament::auth()->user();

                    app(CibilReportService::class)->resolveUncertain($user, $this->record, (string) $data['note']);
                    $this->record->refresh();

                    Notification::make()->success()->title('Request marked as verified')->send();
                }),

            Action::make('fetchAgain')
                ->label('Refresh report')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => CibilAccess::canRefresh(Filament::auth()->user()))
                ->modalHeading(fn (): string => "Request a fresh {$this->record->bureau->label()} report")
                ->modalDescription(fn (): string => "{$this->record->bureau->label()} is asked again for {$this->record->customer_name} ({$this->record->maskedPan()}) and the request is billed. This report stays as it is.")
                ->modalSubmitActionLabel('Send request')
                ->fillForm(fn (): array => [
                    'request_type' => in_array($this->record->request_type, $this->refreshableTypes(), true) ? $this->record->request_type->value : null,
                    'mobile_no' => $this->record->mobile_no,
                    'gender' => $this->record->gender,
                    'date_of_birth' => $this->record->date_of_birth?->toDateString(),
                ])
                ->schema(fn (): array => [
                    CibilRequestFields::requestType($this->refreshableTypes()),
                    CibilRequestFields::mobile()->visible(blank($this->record->mobile_no)),
                    CibilRequestFields::gender()->visible(blank($this->record->gender)),
                    CibilRequestFields::freshReason(),
                    ...CibilRequestFields::consent(fn (Get $get): ?CibilRequestType => CibilRequestType::tryFrom((string) $get('request_type')), $this->record->bureau),
                    CibilRequestFields::afterUncertain(fn (Get $get): bool => $this->hasUnresolvedUncertain($get('request_type'))),
                ])
                ->action(function (array $data, Action $action): void {
                    /** @var User $user */
                    $user = Filament::auth()->user();

                    try {
                        $outcome = app(CibilReportService::class)->refetch($user, $this->record, $data);
                    } catch (AuthorizationException $exception) {
                        abort(403, $exception->getMessage());
                    } catch (CibilRequestRefused $exception) {
                        Notification::make()->warning()->title("{$this->record->bureau->label()} request not sent")->body($exception->getMessage())->persistent()->send();

                        $action->halt();
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title("{$this->record->bureau->label()} request not sent")->body(collect($exception->errors())->flatten()->implode(' '))->send();

                        $action->halt();
                    }

                    $report = $outcome->report;
                    $bureau = $report->bureau->label();

                    Notification::make()
                        ->status(match ($report->status) {
                            CibilReportStatus::Success => 'success',
                            CibilReportStatus::Uncertain => 'warning',
                            default => 'danger',
                        })
                        ->title(match ($report->status) {
                            CibilReportStatus::Success => "{$bureau} report fetched",
                            CibilReportStatus::Uncertain => "{$bureau} request outcome uncertain",
                            default => "{$bureau} report could not be fetched",
                        })
                        ->body($report->status === CibilReportStatus::Success ? ($report->score !== null ? "Score: {$report->score}" : null) : $report->error_message)
                        ->send();

                    $this->redirect(CibilReportResource::getUrl('view', ['record' => $report]));
                }),
        ];
    }

    /**
     * What this login may ask the report's own bureau for again.
     *
     * @return list<CibilRequestType>
     */
    protected function refreshableTypes(): array
    {
        return CibilAccess::requestableTypes(Filament::auth()->user());
    }

    protected function hasUnresolvedUncertain(mixed $type): bool
    {
        $type = CibilRequestType::tryFrom((string) $type);

        return $type !== null && app(CibilReportService::class)->unresolvedUncertain($this->record->pan_number, $type, $this->record->bureau) !== null;
    }

    protected function canDownloadPdf(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(CibilReportService::class)->canDownloadPdf($this->record, $user);
    }

    /**
     * Mark Eligible / Mark Not Eligible: shown while the lead this report
     * was fetched for still waits for the manager's decision.
     */
    protected function decisionAction(BureauDecision $decision): Action
    {
        $eligible = $decision === BureauDecision::Eligible;

        return Action::make($eligible ? 'markEligible' : 'markNotEligible')
            ->label("Mark {$decision->label()}")
            ->icon($eligible ? 'heroicon-o-check-badge' : 'heroicon-o-x-circle')
            ->color($decision->color())
            ->visible(fn (): bool => $this->canDecide())
            ->modalHeading(fn (): string => "Mark {$this->record->customer_name} {$decision->label()}?")
            ->modalDescription($eligible
                ? 'The lead moves to "Eligible" and can then be converted to a customer.'
                : 'The lead moves to "Not Eligible" and its follow-ups stop.')
            ->modalSubmitActionLabel("Mark {$decision->label()}")
            ->schema([
                Textarea::make('remarks')
                    ->label('Remarks (optional)')
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(function (array $data) use ($decision): void {
                /** @var User $user */
                $user = Filament::auth()->user();

                app(CibilReportService::class)->decide($user, $this->record, $decision, $data['remarks'] ?? null);

                $this->record->refresh();

                Notification::make()
                    ->success()
                    ->title("Lead marked {$decision->label()}")
                    ->body($decision === BureauDecision::Eligible && $this->leadIsConvertible() ? 'You can now convert the lead to a customer.' : null)
                    ->send();
            });
    }

    protected function canDecide(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(CibilReportService::class)->canDecide($this->record, $user);
    }

    /** The lead (My Leads or assigned) may move on to a customer file. */
    protected function leadIsConvertible(): bool
    {
        if (! CustomerResource::canCreate()) {
            return false;
        }

        return (bool) ($this->record->lead?->isConvertible() || $this->record->assignedLead?->isEligibleForConversion());
    }

    /**
     * Hands off to the customer creation form, the same way the lead
     * listings do, so the PAN duplicate check and approval flow still apply.
     */
    protected function convertLead(): void
    {
        $lead = $this->record->lead;

        if ($lead !== null) {
            $missingFields = $lead->missingConversionFields();

            if ($missingFields !== []) {
                Notification::make()
                    ->title('Lead cannot be converted')
                    ->body('Please fill the following field(s) on the lead first: '.implode(', ', $missingFields))
                    ->danger()
                    ->persistent()
                    ->send();

                return;
            }

            $this->redirect(CustomerResource::getUrl('create', ['lead' => $lead->id]));

            return;
        }

        $assignedLead = $this->record->assignedLead;

        if ($assignedLead !== null) {
            $this->redirect(CustomerResource::getUrl('create', ['ai_customer_record' => $assignedLead->ai_customer_record_id]));
        }
    }
}
