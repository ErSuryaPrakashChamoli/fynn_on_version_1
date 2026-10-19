<?php

namespace App\Filament\Resources\CibilReports\RelationManagers;

use App\Enums\CibilPdfStatus;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Filament\Resources\CibilReports\Schemas\CibilRequestFields;
use App\Models\CibilReport;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Lead;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Services\Cibil\CibilRequestOutcome;
use App\Services\Cibil\CibilRequestRefused;
use App\Support\CibilAccess;
use App\Support\CibilMask;
use App\Support\CibilSubject;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * "CIBIL Credit Profile" on a customer file, a My Leads lead and an assigned
 * lead: the applicant (PAN and mobile masked), the latest score and request,
 * the PDF, and every request made for the file — plus the request actions,
 * each filled from what the file holds. Opening the file never contacts the
 * bureau; only an explicit action does, through CibilReportService, which
 * re-checks permission, settings, consent and the file's own PAN, and
 * reuses a report the file already has unless a fresh pull is asked for.
 *
 * Shown to whoever has CIBIL module access and can see the file (the owning
 * resource's own scope); on a customer or assigned lead it sits on the view
 * page, on a My Leads lead (which has no view page) on the edit page.
 *
 * @property Lead|CustomerAssignment|Customer $ownerRecord
 */
class CibilProfileRelationManager extends RelationManager
{
    protected static string $relationship = 'cibilReports';

    protected static ?string $title = 'CIBIL Credit Profile';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! CibilAccess::canUse(Filament::auth()->user())) {
            return false;
        }

        return $ownerRecord instanceof Lead || ! is_subclass_of($pageClass, EditRecord::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('CIBIL Credit Profile')
            ->description(fn (): HtmlString => new HtmlString(view('filament.components.cibil-profile-summary', $this->profile())->render()))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['requester.employee']))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No CIBIL request yet')
            ->emptyStateDescription('Opening this file never contacts a bureau. Use the buttons above to request a score or report from CIBIL or Experian.')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),

                TextColumn::make('bureau')
                    ->label('Bureau')
                    ->badge()
                    ->formatStateUsing(fn (CreditBureau $state): string => $state->label())
                    ->color(fn (CreditBureau $state): string => $state->color()),

                TextColumn::make('request_type')
                    ->label('Request')
                    ->badge()
                    ->formatStateUsing(fn (CibilRequestType $state): string => $state->label())
                    ->color(fn (CibilRequestType $state): string => $state->color()),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (CibilReportStatus $state): string => $state->label())
                    ->color(fn (CibilReportStatus $state): string => $state->color())
                    ->tooltip(fn (CibilReport $record): ?string => $record->error_message),

                TextColumn::make('score')
                    ->label('Score')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => $state < 300 ? 'NH' : (string) $state)
                    ->color(fn (CibilReport $record): string => $record->scoreBand()?->color() ?? 'gray'),

                TextColumn::make('pdf_status')
                    ->label('PDF')
                    ->badge()
                    ->formatStateUsing(fn (CibilPdfStatus $state): string => $state->label())
                    ->color(fn (CibilPdfStatus $state): string => $state->color())
                    ->placeholder('—'),

                TextColumn::make('requester.name')
                    ->label('Requested by')
                    ->placeholder('Former user')
                    ->description(fn (CibilReport $record): ?string => $record->requester?->employee?->emp_id),

                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('—'),

                TextColumn::make('bureau_reference')
                    ->label('Provider reference')
                    ->placeholder('—')
                    ->copyable()
                    ->visible(fn (): bool => CibilAccess::canViewAudit(Filament::auth()->user())),
            ])
            ->headerActions([
                $this->requestAction(CibilRequestType::Score),
                $this->requestAction(CibilRequestType::FullReport),
                $this->requestAction(CibilRequestType::Pdf),
                $this->refreshAction(),
            ])
            ->recordActions([
                Action::make('viewReport')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (CibilReport $record): string => CibilReportResource::getUrl('view', ['record' => $record])),

                Action::make('openPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn (CibilReport $record): bool => $this->viewer() instanceof User && app(CibilReportService::class)->canDownloadPdf($record, $this->viewer()))
                    ->url(fn (CibilReport $record): ?string => $record->pdfUrl())
                    ->openUrlInNewTab(),
            ]);
    }

    protected function requestAction(CibilRequestType $type): Action
    {
        return Action::make(match ($type) {
            CibilRequestType::Score => 'checkCibilScore',
            CibilRequestType::FullReport => 'fetchCibilReport',
            CibilRequestType::Pdf => 'fetchCibilPdf',
        })
            ->label(match ($type) {
                CibilRequestType::Score => 'Check credit score',
                CibilRequestType::FullReport => 'Fetch full report',
                CibilRequestType::Pdf => 'Get PDF report',
            })
            ->icon(match ($type) {
                CibilRequestType::Score => 'heroicon-o-chart-bar',
                CibilRequestType::FullReport => 'heroicon-o-document-text',
                CibilRequestType::Pdf => 'heroicon-o-document-arrow-down',
            })
            ->color($type === CibilRequestType::Score ? 'primary' : 'gray')
            ->visible(fn (): bool => CibilAccess::canRequest($this->viewer(), $type))
            ->modalHeading("{$type->label()} — {$this->subjectName()}")
            ->modalDescription('Choose the bureau first. If this file already has a report from that bureau that answers this, it is opened instead and nothing is billed. Otherwise the bureau is asked and the request is billed.')
            ->modalSubmitActionLabel('Send request')
            ->fillForm(fn (): array => $this->prefill())
            ->schema(fn (): array => [
                CibilRequestFields::bureau(),
                ...$this->applicantFields(),
                ...CibilRequestFields::consent($type, fn (Get $get): ?CreditBureau => CreditBureau::tryFrom((string) $get('bureau'))),
            ])
            ->action(function (array $data, Action $action) use ($type): void {
                $this->submit($action, $type, $data, fresh: false);
            });
    }

    protected function refreshAction(): Action
    {
        return Action::make('refreshCibil')
            ->label('Refresh bureau report')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (): bool => CibilAccess::canRefresh($this->viewer()))
            ->modalHeading("Fresh bureau request — {$this->subjectName()}")
            ->modalDescription('Asks the chosen bureau again even though a report is on file. The request is billed; earlier reports stay as they are.')
            ->modalSubmitActionLabel('Send request')
            ->fillForm(fn (): array => $this->prefill())
            ->schema(fn (): array => [
                CibilRequestFields::bureau(),
                CibilRequestFields::requestType(CibilAccess::requestableTypes($this->viewer())),
                ...$this->applicantFields(),
                CibilRequestFields::freshReason(),
                ...CibilRequestFields::consent(
                    fn (Get $get): ?CibilRequestType => CibilRequestType::tryFrom((string) $get('request_type')),
                    fn (Get $get): ?CreditBureau => CreditBureau::tryFrom((string) $get('bureau')),
                ),
                CibilRequestFields::afterUncertain(fn (Get $get): bool => $this->hasUnresolvedUncertain($get('request_type'), $get('bureau'))),
            ])
            ->action(function (array $data, Action $action): void {
                $type = CibilRequestType::tryFrom((string) ($data['request_type'] ?? '')) ?? CibilRequestType::Score;

                $this->submit($action, $type, $data, fresh: true);
            });
    }

    /**
     * @return list<mixed>
     */
    protected function applicantFields(): array
    {
        return [
            CibilRequestFields::name(),
            CibilRequestFields::pan(fn (): bool => $this->filePan() !== null),
            CibilRequestFields::mobile(),
            CibilRequestFields::gender(),
            CibilRequestFields::dateOfBirth(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function submit(Action $action, CibilRequestType $type, array $data, bool $fresh): void
    {
        $user = $this->viewer();
        $subject = $this->getOwnerRecord();

        // The owner record came from the page; it must still be one this user can see.
        if (! $user instanceof User || ! CibilSubject::isVisible($subject)) {
            abort(404);
        }

        // What the file holds wins over anything posted for it.
        $details = CibilSubject::details($subject);
        $data['pan_number'] = $details['pan_number'] ?? ($data['pan_number'] ?? null);
        $data['email'] = $details['email'];
        $bureau = CreditBureau::tryFrom((string) ($data['bureau'] ?? ''));

        if ($bureau === null) {
            Notification::make()->danger()->title('Request not sent')->body('Choose the bureau to request from.')->send();

            $action->halt();

            return;
        }

        try {
            $outcome = app(CibilReportService::class)->request($user, $type, $data, $subject, $fresh, $bureau);
        } catch (AuthorizationException $exception) {
            Notification::make()->danger()->title('Not allowed')->body($exception->getMessage())->send();

            $action->cancel();
        } catch (CibilRequestRefused $exception) {
            Notification::make()->warning()->title("{$bureau->label()} request not sent")->body($exception->getMessage())->persistent()->send();

            $action->cancel();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title("{$bureau->label()} request not sent")->body(collect($exception->errors())->flatten()->implode(' '))->send();

            $action->halt();
        }

        $this->notifyOutcome($outcome);
    }

    protected function notifyOutcome(CibilRequestOutcome $outcome): void
    {
        $report = $outcome->report;
        $bureau = $report->bureau->label();
        $open = Action::make('open')
            ->label('Open report')
            ->url(CibilReportResource::getUrl('view', ['record' => $report]));

        $notification = match (true) {
            $outcome->reused => Notification::make()
                ->info()
                ->title('Existing report used')
                ->body("This file already has a {$bureau} {$report->request_type->label()} from {$report->fetched_at?->format('d M Y')}. No new request was sent to {$bureau}."),
            $report->status === CibilReportStatus::Success => Notification::make()
                ->success()
                ->title($report->score !== null ? "{$bureau} score: {$report->score}" : "{$bureau} {$report->request_type->label()} fetched")
                ->body($report->score === null ? "{$bureau} returned no score for this applicant." : null),
            $report->status === CibilReportStatus::Uncertain => Notification::make()
                ->warning()
                ->title("{$bureau} request outcome uncertain")
                ->body($report->error_message)
                ->persistent(),
            default => Notification::make()
                ->danger()
                ->title("{$bureau} request failed")
                ->body($report->error_message),
        };

        $notification->actions([$open])->send();
    }

    /**
     * The summary above the history.
     *
     * @return array<string, mixed>
     */
    protected function profile(): array
    {
        $subject = $this->getOwnerRecord();
        $details = CibilSubject::details($subject);
        $reports = $subject->cibilReports();

        $latestSuccess = (clone $reports)->where('status', CibilReportStatus::Success)->latest('fetched_at')->latest('id')->first();
        $latestRequest = (clone $reports)->latest('id')->first();
        $latestPdf = (clone $reports)->where('request_type', CibilRequestType::Pdf)->where('status', CibilReportStatus::Success)->latest('id')->first();

        // The newest successful request from each bureau, side by side.
        $latestByBureau = collect(CreditBureau::cases())
            ->mapWithKeys(fn (CreditBureau $bureau): array => [$bureau->label() => (clone $reports)
                ->where('bureau', $bureau)
                ->where('status', CibilReportStatus::Success)
                ->latest('fetched_at')
                ->latest('id')
                ->first()])
            ->all();

        return [
            'type' => CibilSubject::typeLabel($subject),
            'latestByBureau' => $latestByBureau,
            'requestCount' => (clone $reports)->count(),
            'name' => $details['customer_name'] ?: $latestRequest?->customer_name,
            'pan' => CibilMask::pan($details['pan_number'] ?? $latestRequest?->pan_number),
            'mobile' => CibilMask::mobile($details['mobile_no'] ?? $latestRequest?->mobile_no),
            'latestSuccess' => $latestSuccess,
            'latestRequest' => $latestRequest,
            'latestPdf' => $latestPdf,
            'pdfUrl' => $latestPdf !== null && $this->viewer() instanceof User && app(CibilReportService::class)->canDownloadPdf($latestPdf, $this->viewer())
                ? $latestPdf->pdfUrl()
                : null,
        ];
    }

    /**
     * What the file holds, plus gender and date of birth from its last
     * request (files do not keep those).
     *
     * @return array<string, mixed>
     */
    protected function prefill(): array
    {
        $subject = $this->getOwnerRecord();
        $details = CibilSubject::details($subject);
        $previous = $subject->cibilReports()->latest('id')->first();

        return [
            'bureau' => CreditBureau::Cibil->value,
            'request_type' => CibilRequestType::Score->value,
            'customer_name' => $details['customer_name'] ?: $previous?->customer_name,
            'pan_number' => $details['pan_number'] ?? $previous?->pan_number,
            'mobile_no' => $details['mobile_no'] ?? $previous?->mobile_no,
            'gender' => $previous?->gender,
            'date_of_birth' => $previous?->date_of_birth?->toDateString(),
        ];
    }

    protected function filePan(): ?string
    {
        return CibilSubject::details($this->getOwnerRecord())['pan_number'];
    }

    protected function hasUnresolvedUncertain(mixed $type, mixed $bureau): bool
    {
        $type = CibilRequestType::tryFrom((string) $type);
        $bureau = CreditBureau::tryFrom((string) $bureau);
        $pan = $this->filePan() ?? $this->getOwnerRecord()->cibilReports()->latest('id')->value('pan_number');

        return $type !== null && $bureau !== null && $pan !== null
            && app(CibilReportService::class)->unresolvedUncertain($pan, $type, $bureau) !== null;
    }

    protected function subjectName(): string
    {
        return (string) (CibilSubject::details($this->getOwnerRecord())['customer_name'] ?: CibilSubject::typeLabel($this->getOwnerRecord()));
    }

    protected function viewer(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }
}
