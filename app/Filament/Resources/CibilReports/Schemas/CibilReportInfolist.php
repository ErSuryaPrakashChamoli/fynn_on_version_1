<?php

namespace App\Filament\Resources\CibilReports\Schemas;

use App\Enums\BureauDecision;
use App\Enums\CibilConsentMethod;
use App\Enums\CibilFailureCategory;
use App\Enums\CibilPdfStatus;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\CibilReport;
use App\Models\CustomerAssignment;
use App\Models\Lead;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\BureauCheckCandidates;
use App\Support\CibilAccess;
use App\Support\ExperianReport;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Spatie\Activitylog\Models\Activity;

class CibilReportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(fn (CibilReport $record): string => "{$record->bureau->label()} score")
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('score')
                                ->label('Score')
                                ->size(TextSize::Large)
                                ->weight('bold')
                                ->badge()
                                ->placeholder(fn (CibilReport $record): string => $record->status === CibilReportStatus::Success ? 'No score returned' : '—')
                                ->formatStateUsing(fn (int $state): string => $state < 300 ? 'NH (no history)' : (string) $state)
                                ->color(fn (CibilReport $record): string => $record->scoreBand()?->color() ?? 'gray')
                                ->helperText(fn (CibilReport $record): ?string => $record->scoreBand()?->label()),

                            TextEntry::make('status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn (CibilReportStatus $state): string => $state->label())
                                ->color(fn (CibilReportStatus $state): string => $state->color()),

                            TextEntry::make('request_type')
                                ->label('Request')
                                ->badge()
                                ->formatStateUsing(fn (CibilRequestType $state): string => $state->label())
                                ->color(fn (CibilRequestType $state): string => $state->color()),

                            TextEntry::make('bureau')
                                ->label('Bureau')
                                ->badge()
                                ->formatStateUsing(fn (CreditBureau $state): string => $state->label())
                                ->color(fn (CreditBureau $state): string => $state->color())
                                ->icon(fn (CreditBureau $state): string => $state->icon()),

                            TextEntry::make('fetched_at')
                                ->label('Fetched on')
                                ->dateTime('d M Y, h:i A')
                                ->placeholder('Not fetched'),

                            TextEntry::make('pdf_status')
                                ->label('PDF')
                                ->badge()
                                ->formatStateUsing(fn (CibilPdfStatus $state): string => $state->label())
                                ->color(fn (CibilPdfStatus $state): string => $state->color())
                                ->visible(fn (CibilReport $record): bool => $record->request_type === CibilRequestType::Pdf && $record->status === CibilReportStatus::Success),

                            TextEntry::make('completed_at')
                                ->label('Completed on')
                                ->dateTime('d M Y, h:i A')
                                ->placeholder('—'),

                            TextEntry::make('bureau_reference')
                                ->label('Provider reference')
                                ->placeholder('—')
                                ->copyable()
                                ->visible(fn (): bool => self::viewerAudits()),

                            TextEntry::make('provider_status_code')
                                ->label('Provider response')
                                ->formatStateUsing(fn (int $state, CibilReport $record): string => trim("HTTP {$state} ".($record->provider_message_code ?? '')))
                                ->placeholder('—')
                                ->visible(fn (): bool => self::viewerAudits()),

                            TextEntry::make('uuid')
                                ->label('Request ID')
                                ->fontFamily('mono')
                                ->copyable()
                                ->placeholder('—')
                                ->visible(fn (): bool => self::viewerAudits()),

                            TextEntry::make('duration_ms')
                                ->label('Provider time')
                                ->formatStateUsing(fn (int $state): string => $state >= 1000 ? round($state / 1000, 1).' s' : "{$state} ms")
                                ->placeholder('—')
                                ->visible(fn (): bool => self::viewerAudits()),

                            TextEntry::make('provider_contact')
                                ->label('Reached provider')
                                ->state(fn (CibilReport $record): string => $record->providerContact())
                                ->visible(fn (): bool => self::viewerAudits()),

                            TextEntry::make('failure_category')
                                ->label('Failure type')
                                ->badge()
                                ->formatStateUsing(fn (CibilFailureCategory $state): string => $state->label())
                                ->color(fn (CibilFailureCategory $state): string => $state->color())
                                ->visible(fn (CibilReport $record): bool => $record->failure_category !== null),

                            TextEntry::make('pdf_size_bytes')
                                ->label('PDF size')
                                ->formatStateUsing(fn (int $state): string => $state >= 1048576 ? round($state / 1048576, 1).' MB' : round($state / 1024).' KB')
                                ->helperText(fn (CibilReport $record): ?string => $record->pdf_fetched_at ? 'Saved '.$record->pdf_fetched_at->format('d M Y, h:i A') : null)
                                ->visible(fn (CibilReport $record): bool => $record->hasPdf() && $record->pdf_size_bytes !== null),

                            TextEntry::make('pdf_checksum')
                                ->label('PDF SHA-256')
                                ->fontFamily('mono')
                                ->limit(16)
                                ->tooltip(fn (CibilReport $record): ?string => $record->pdf_checksum)
                                ->copyable()
                                ->visible(fn (CibilReport $record): bool => $record->hasPdf() && filled($record->pdf_checksum) && self::viewerAudits()),
                        ]),

                        TextEntry::make('error_message')
                            ->label(fn (CibilReport $record): string => $record->status === CibilReportStatus::Uncertain ? 'Why the outcome is uncertain' : 'Why it failed')
                            ->color(fn (CibilReport $record): string => $record->status === CibilReportStatus::Uncertain ? 'warning' : 'danger')
                            ->visible(fn (CibilReport $record): bool => in_array($record->status, [CibilReportStatus::Failed, CibilReportStatus::Uncertain], true))
                            ->columnSpanFull(),

                        TextEntry::make('resolution_note')
                            ->label('Verified')
                            ->state(fn (CibilReport $record): string => trim(($record->resolver?->name ?? 'Former user').' on '.$record->resolved_at?->format('d M Y, h:i A').': '.$record->resolution_note))
                            ->visible(fn (CibilReport $record): bool => $record->resolved_at !== null)
                            ->columnSpanFull(),

                        TextEntry::make('request_reason')
                            ->label('Why this request was sent')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Bureau decision')
                    ->description(fn (CibilReport $record): ?string => self::decisionPrompt($record))
                    ->columnSpanFull()
                    ->visible(fn (CibilReport $record): bool => $record->isForLead())
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('bureau_decision')
                                ->label('Decision')
                                ->badge()
                                ->formatStateUsing(fn (BureauDecision $state): string => $state->label())
                                ->color(fn (BureauDecision $state): string => $state->color())
                                ->placeholder('Awaiting decision'),
                            TextEntry::make('decider.name')
                                ->label('Decided by')
                                ->placeholder('—'),
                            TextEntry::make('decided_at')
                                ->label('Decided on')
                                ->dateTime('d M Y, h:i A')
                                ->placeholder('—'),
                        ]),

                        TextEntry::make('decision_remarks')
                            ->label('Remarks')
                            ->visible(fn (CibilReport $record): bool => filled($record->decision_remarks))
                            ->columnSpanFull(),
                    ]),

                Section::make('Customer details')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('customer_name')->label('Name'),
                            TextEntry::make('pan_number')->label('PAN')->copyable(),
                            TextEntry::make('mobile_no')->label('Mobile'),
                            TextEntry::make('email')->label('Email')->placeholder('—'),
                            TextEntry::make('date_of_birth')->label('Date of birth')->date('d M Y')->placeholder('—'),
                            TextEntry::make('gender')
                                ->label('Gender')
                                ->formatStateUsing(fn (string $state): string => CibilReport::GENDERS[$state] ?? $state)
                                ->placeholder('—'),
                            TextEntry::make('pincode')->label('PIN code')->placeholder('—'),
                            TextEntry::make('fetched_for')
                                ->label('Fetched for')
                                ->state(fn (CibilReport $record): ?string => self::subjectLabel($record))
                                ->placeholder('Not linked')
                                ->url(fn (CibilReport $record): ?string => self::subjectUrl($record))
                                ->color(fn (CibilReport $record): ?string => self::subjectUrl($record) ? 'primary' : null),
                        ]),
                    ]),

                Section::make('Fetched by')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('requester.name')
                                ->label('Fetched by')
                                ->placeholder('Former user')
                                ->helperText(fn (CibilReport $record): ?string => $record->requester?->employee?->emp_id),
                            TextEntry::make('created_at')->label('Requested on')->dateTime('d M Y, h:i A'),
                            TextEntry::make('consent_given_at')->label('Customer consent recorded')->dateTime('d M Y, h:i A'),
                        ]),
                        Grid::make(3)->schema([
                            TextEntry::make('consent_method')
                                ->label('How consent was given')
                                ->formatStateUsing(fn (CibilConsentMethod $state): string => $state->label())
                                ->placeholder('Not recorded'),
                            TextEntry::make('consent_reference')->label('Consent reference')->placeholder('—'),
                            TextEntry::make('consent_purpose')->label('Purpose of the enquiry')->placeholder('—'),
                        ]),
                    ]),

                Section::make('Full report')
                    ->description(fn (CibilReport $record): string => ExperianReport::fromPayload($record->response_payload) !== null && $record->bureau === CreditBureau::Experian
                        ? 'The Experian report, section by section. Fields the report does not carry are marked "Not available in this report".'
                        : 'As received from the bureau.')
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn (CibilReport $record): bool => filled($record->response_payload) && self::viewerReadsFullReports())
                    ->schema([
                        View::make('filament.components.experian-report')
                            ->visible(fn (CibilReport $record): bool => $record->bureau === CreditBureau::Experian && ExperianReport::fromPayload($record->response_payload) !== null)
                            ->viewData(fn (CibilReport $record): array => [
                                'report' => ExperianReport::fromPayload($record->response_payload),
                                'score' => $record->score,
                            ]),

                        View::make('filament.components.cibil-report-payload')
                            ->visible(fn (CibilReport $record): bool => $record->bureau !== CreditBureau::Experian || ExperianReport::fromPayload($record->response_payload) === null)
                            ->viewData(fn (CibilReport $record): array => ['payload' => $record->response_payload ?? []]),
                    ]),

                Section::make('Audit trail')
                    ->description('Everything logged for this request: sent, reused, viewed, PDF opened, verified.')
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (): bool => self::viewerAudits())
                    ->schema([
                        View::make('filament.components.cibil-audit-trail')
                            ->viewData(fn (CibilReport $record): array => [
                                'entries' => Activity::query()
                                    ->with('causer')
                                    ->where('subject_type', $record->getMorphClass())
                                    ->where('subject_id', $record->getKey())
                                    ->latest('id')
                                    ->limit(100)
                                    ->get(),
                            ]),
                    ]),

                Section::make('Previous reports for this PAN')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        View::make('filament.components.cibil-report-history')
                            ->viewData(fn (CibilReport $record): array => ['reports' => self::history($record)]),
                    ]),
            ]);
    }

    protected static function subjectLabel(CibilReport $record): ?string
    {
        $subject = $record->subject();

        return match (true) {
            $subject instanceof Lead, $subject instanceof CustomerAssignment => $record->subjectType().' · '.BureauCheckCandidates::leadLabel($subject),
            $subject !== null => $record->subjectType().' · '.BureauCheckCandidates::customerLabel($subject),
            default => null,
        };
    }

    /** A link to the lead or customer file, when the viewer can open it. */
    protected static function subjectUrl(CibilReport $record): ?string
    {
        return match (true) {
            $record->lead_id !== null => LeadResource::getEloquentQuery()->whereKey($record->lead_id)->exists()
                ? LeadResource::getUrl('edit', ['record' => $record->lead_id])
                : null,
            $record->customer_assignment_id !== null => AssignedLeadResource::getEloquentQuery()->whereKey($record->customer_assignment_id)->exists()
                ? AssignedLeadResource::getUrl('view', ['record' => $record->customer_assignment_id])
                : null,
            $record->customer_id !== null => CustomerResource::getEloquentQuery()->whereKey($record->customer_id)->exists()
                ? CustomerResource::getUrl('view', ['record' => $record->customer_id])
                : null,
            default => null,
        };
    }

    /** What the manager is asked to do about the lead, if anything. */
    protected static function decisionPrompt(CibilReport $record): ?string
    {
        $user = Filament::auth()->user();

        return match (true) {
            $record->bureau_decision !== null => null,
            $record->status !== CibilReportStatus::Success => 'No decision can be made until the report is fetched. Use "Fetch again" once the bureau answers.',
            $user instanceof User && app(CibilReportService::class)->canDecide($record, $user) => 'Mark the lead Eligible or Not Eligible with the buttons at the top of the page. The lead\'s status changes to match.',
            default => 'The lead is no longer marked "Bureau Check", so no decision is asked for on this report.',
        };
    }

    /**
     * @return iterable<CibilReport>
     */
    protected static function history(CibilReport $record): iterable
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            ? app(CibilReportService::class)->historyFor($record, $user)
            : [];
    }

    /** Provider references and response codes are for the Admin and the audit permission. */
    protected static function viewerAudits(): bool
    {
        return CibilAccess::canViewAudit(Filament::auth()->user());
    }

    protected static function viewerReadsFullReports(): bool
    {
        return CibilAccess::canViewFullReport(Filament::auth()->user());
    }
}
