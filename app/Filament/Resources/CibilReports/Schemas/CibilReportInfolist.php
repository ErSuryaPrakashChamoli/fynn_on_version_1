<?php

namespace App\Filament\Resources\CibilReports\Schemas;

use App\Enums\BureauDecision;
use App\Enums\CibilReportStatus;
use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\CibilReport;
use App\Models\CustomerAssignment;
use App\Models\Lead;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\BureauCheckCandidates;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

class CibilReportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('CIBIL score')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('score')
                                ->label('Score')
                                ->size(TextSize::Large)
                                ->weight('bold')
                                ->badge()
                                ->placeholder('—')
                                ->formatStateUsing(fn (int $state): string => $state < 300 ? 'NH (no history)' : (string) $state)
                                ->color(fn (CibilReport $record): string => $record->scoreBand()?->color() ?? 'gray')
                                ->helperText(fn (CibilReport $record): ?string => $record->scoreBand()?->label()),

                            TextEntry::make('status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn (CibilReportStatus $state): string => $state->label())
                                ->color(fn (CibilReportStatus $state): string => $state->color()),

                            TextEntry::make('fetched_at')
                                ->label('Fetched on')
                                ->dateTime('d M Y, h:i A')
                                ->placeholder('Not fetched'),

                            TextEntry::make('bureau_reference')
                                ->label('Bureau reference')
                                ->placeholder('—')
                                ->copyable(),
                        ]),

                        TextEntry::make('error_message')
                            ->label('Why it failed')
                            ->color('danger')
                            ->visible(fn (CibilReport $record): bool => $record->status === CibilReportStatus::Failed)
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
                    ]),

                Section::make('Full report')
                    ->description('As received from the bureau.')
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn (CibilReport $record): bool => filled($record->response_payload))
                    ->schema([
                        View::make('filament.components.cibil-report-payload')
                            ->viewData(fn (CibilReport $record): array => ['payload' => $record->response_payload ?? []]),
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
}
