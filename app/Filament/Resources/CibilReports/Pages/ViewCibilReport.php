<?php

namespace App\Filament\Resources\CibilReports\Pages;

use App\Enums\BureauDecision;
use App\Enums\CibilReportStatus;
use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CibilReport;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

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
        return 'CIBIL report — '.$this->record->customer_name;
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

            Action::make('fetchAgain')
                ->label('Fetch again')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => CibilAccess::canUse(Filament::auth()->user()))
                ->modalHeading('Fetch a fresh CIBIL report')
                ->modalDescription(fn (): string => "The bureau is asked again for {$this->record->customer_name} ({$this->record->pan_number}). This report stays as it is.")
                ->modalSubmitActionLabel('Fetch')
                ->fillForm(fn (): array => ['date_of_birth' => $this->record->date_of_birth?->toDateString()])
                ->schema([
                    DatePicker::make('date_of_birth')
                        ->label('Date of birth')
                        ->required()
                        ->maxDate(now()->subDay()),
                    Checkbox::make('consent')
                        ->label('The customer has given consent for their CIBIL report to be fetched again.')
                        ->accepted()
                        ->validationMessages(['accepted' => 'The customer\'s consent is required before a CIBIL report can be fetched.']),
                ])
                ->action(function (array $data): void {
                    /** @var User $user */
                    $user = Filament::auth()->user();

                    $report = app(CibilReportService::class)->refetch(
                        $user,
                        $this->record,
                        (bool) ($data['consent'] ?? false),
                        $data['date_of_birth'] ?? null,
                    );

                    $fetched = $report->status === CibilReportStatus::Success;

                    Notification::make()
                        ->status($fetched ? 'success' : 'danger')
                        ->title($fetched ? 'CIBIL report fetched' : 'CIBIL report could not be fetched')
                        ->body($fetched ? ($report->score !== null ? "Score: {$report->score}" : null) : $report->error_message)
                        ->send();

                    $this->redirect(CibilReportResource::getUrl('view', ['record' => $report]));
                }),
        ];
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
