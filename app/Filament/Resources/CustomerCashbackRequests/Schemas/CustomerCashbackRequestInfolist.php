<?php

namespace App\Filament\Resources\CustomerCashbackRequests\Schemas;

use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackRequestStatus;
use App\Enums\CashbackReturnStatus;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestActions;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CustomerCashbackRequest;
use App\Services\CashbackAnalysisService;
use App\Services\CustomerCashbackService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * The cashback analysis of everyone on the case's chain (saved when the
 * form was raised, and as on now while it waits for approval), then the
 * voucher, laid out like the paper Acknowledgment form, then — when the
 * case has more than one form (a second payment, a revision) — every form
 * on the case with who filled, signed off, approved and paid each and the
 * case's total, then who did what on this form and its change history.
 */
class CustomerCashbackRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Summary')
                    ->icon(Heroicon::OutlinedReceiptRefund)
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('customer.customer_name')
                                ->label('Customer file')
                                ->url(fn (CustomerCashbackRequest $record): ?string => $record->customer
                                    ? CustomerResource::getUrl('view', ['record' => $record->customer])
                                    : null)
                                ->color('primary'),
                            TextEntry::make('status')
                                ->label('Approval')
                                ->badge()
                                ->formatStateUsing(fn (CashbackRequestStatus $state): string => $state->label())
                                ->color(fn (CashbackRequestStatus $state): string => $state->color()),
                            TextEntry::make('payment_status')
                                ->label('Payment')
                                ->badge()
                                ->formatStateUsing(fn (CashbackPaymentStatus $state): string => $state->label())
                                ->color(fn (CashbackPaymentStatus $state): string => $state->color()),
                            TextEntry::make('requester.name')
                                ->label('Filled by')
                                ->helperText(fn (CustomerCashbackRequest $record): ?string => $record->created_at?->format('d M Y, h:i A')),
                        ]),

                        Grid::make(4)->schema([
                            TextEntry::make('supervisor.emp_name')
                                ->label('Supervisor approval')
                                ->placeholder('Not needed — went straight to the Admin')
                                ->helperText(fn (CustomerCashbackRequest $record): ?string => self::supervisorState($record))
                                ->color(fn (CustomerCashbackRequest $record): ?string => $record->escalated_at !== null || $record->isPastSupervisorTat() ? 'danger' : null)
                                ->columnSpan(2),
                            TextEntry::make('revision_of_id')
                                ->label('Revision of')
                                ->visible(fn (CustomerCashbackRequest $record): bool => $record->isRevision())
                                ->state(fn (CustomerCashbackRequest $record): ?string => $record->revisionLabel())
                                ->url(fn (CustomerCashbackRequest $record): ?string => $record->original
                                    ? CustomerCashbackRequestResource::getUrl('view', ['record' => $record->original])
                                    : null)
                                ->color('primary')
                                ->columnSpan(2),
                        ]),
                    ]),

                Section::make('Cashback analysis')
                    ->key('cashbackAnalysis')
                    ->icon(Heroicon::OutlinedPercentBadge)
                    ->description('Cases punched in, cashback and subvention (amounts, cases and % of disbursal, apart and together), disbursal and approval amounts of everyone on this case\'s chain, for the month the case counts in — what to weigh before approving.')
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed(fn (CustomerCashbackRequest $record): bool => ! $record->isPending())
                    ->schema([
                        View::make('filament.components.cashback-form-analysis')
                            ->viewData(fn (CustomerCashbackRequest $record): array => self::analysisViewData($record)),
                    ]),

                Section::make('Voucher')
                    ->key('cashbackVoucher')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->description(fn (CustomerCashbackRequest $record): ?string => $record->isApproved() && ! $record->isPaid()
                        ? 'Admin / Accounts: use Record payment to fill the UTR / transaction ID, paid by, payment date and status.'
                        : null)
                    ->headerActions([
                        CustomerCashbackRequestActions::updatePayment(),
                    ])
                    ->columnSpanFull()
                    ->schema([
                        View::make('filament.components.cashback-voucher')
                            ->viewData(fn (CustomerCashbackRequest $record): array => [
                                'voucher' => $record->loadMissing(['customer', 'aro', 'teamLeader', 'manager', 'clusterManager', 'requester', 'managerApprover', 'approver', 'payer', 'returnRequester', 'returnReviewer', 'original']),
                            ]),
                    ]),

                Section::make('Cashback forms on this case')
                    ->key('caseCashbackForms')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->description('Each cashback on this case is its own form with its own approval and UTR; the case\'s cashback is their total.')
                    ->columnSpanFull()
                    ->visible(fn (CustomerCashbackRequest $record): bool => $record->customer !== null && $record->customer->cashbackRequests()->count() > 1)
                    ->schema([
                        View::make('filament.components.cashback-case-forms')
                            ->viewData(fn (CustomerCashbackRequest $record): array => [
                                'forms' => $record->customer->cashbackRequests()
                                    ->with(['requester', 'supervisor', 'managerApprover', 'approver', 'rejecter', 'retriever', 'returnRequester', 'returnReviewer'])
                                    ->oldest('id')
                                    ->get(),
                                'currentId' => $record->getKey(),
                                'cashbackBeforeForms' => (float) ($record->customer->cashback_before_forms ?? 0),
                            ]),
                    ]),

                Section::make('Approval trail')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('remarks')->label('Remarks with the form')->placeholder('—'),
                            TextEntry::make('manager_note')
                                ->label('Manager\'s note')
                                ->placeholder('—')
                                ->helperText(fn (CustomerCashbackRequest $record): ?string => $record->managerApprover
                                    ? $record->managerApprover->name.' · '.$record->manager_approved_at?->format('d M Y, h:i A')
                                    : null),
                            TextEntry::make('approval_note')
                                ->label('Admin\'s note')
                                ->placeholder('—')
                                ->helperText(fn (CustomerCashbackRequest $record): ?string => $record->approver
                                    ? $record->approver->name.' · '.$record->approved_at?->format('d M Y, h:i A')
                                    : null),
                            TextEntry::make('rejection_reason')
                                ->label('Rejected because')
                                ->color('danger')
                                ->visible(fn (CustomerCashbackRequest $record): bool => $record->status === CashbackRequestStatus::Rejected)
                                ->helperText(fn (CustomerCashbackRequest $record): ?string => $record->rejecter?->name.' · '.$record->rejected_at?->format('d M Y, h:i A')),
                            TextEntry::make('retrieval_reason')
                                ->label('Retrieved because')
                                ->visible(fn (CustomerCashbackRequest $record): bool => $record->status === CashbackRequestStatus::Retrieved)
                                ->helperText(fn (CustomerCashbackRequest $record): ?string => $record->retriever?->name.' · '.$record->retrieved_at?->format('d M Y, h:i A')),
                            TextEntry::make('return_reason')
                                ->label(fn (CustomerCashbackRequest $record): string => match ($record->return_status) {
                                    CashbackReturnStatus::Approved => 'Customer returned ₹'.indianNumberFormat($record->returnedAmount()).($record->isFullyReturned() ? ' (all of it)' : ' of ₹'.indianNumberFormat($record->effectiveAmount())),
                                    CashbackReturnStatus::Rejected => 'Return of ₹'.indianNumberFormat($record->returned_amount).' rejected by the Admin',
                                    default => 'Return of ₹'.indianNumberFormat($record->returned_amount).' — waiting for the Admin',
                                })
                                ->color(fn (CustomerCashbackRequest $record): string => $record->return_status?->color() ?? 'gray')
                                ->visible(fn (CustomerCashbackRequest $record): bool => $record->return_status !== null)
                                ->helperText(fn (CustomerCashbackRequest $record): string => self::returnTrail($record))
                                ->columnSpanFull(),
                            TextEntry::make('payment_updated_at')
                                ->label('Payment last updated')
                                ->dateTime('d M Y, h:i A')
                                ->placeholder('—')
                                ->helperText(fn (CustomerCashbackRequest $record): ?string => $record->paymentUpdater?->name),
                        ]),
                    ]),

                Section::make('History')
                    ->icon(Heroicon::OutlinedClock)
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        RepeatableEntry::make('history')
                            ->hiddenLabel()
                            ->state(fn (CustomerCashbackRequest $record): array => self::history($record))
                            ->schema([
                                TextEntry::make('when')->label('When'),
                                TextEntry::make('who')->label('Who'),
                                TextEntry::make('what')->label('What')->columnSpan(2),
                            ])
                            ->columns(4)
                            ->placeholder('No changes recorded yet.'),
                    ]),
            ]);
    }

    /**
     * What cashback-form-analysis.blade.php shows for a form: the analysis
     * saved when it was raised and — while it waits for approval, or when
     * nothing was saved (forms raised before analyses were kept) — the
     * analysis as it stands now. $withSaved false gives only today's (the
     * approve / reject pop-ups).
     *
     * @return array{blocks: list<array{title: string, analysis: array<string, mixed>}>, note: string|null}
     */
    public static function analysisViewData(CustomerCashbackRequest $record, bool $withSaved = true): array
    {
        $saved = $withSaved && is_array($record->analysis_snapshot) && $record->analysis_snapshot !== [] ? $record->analysis_snapshot : null;
        $blocks = [];

        if ($saved !== null) {
            $blocks[] = [
                'title' => 'When the form was raised · '.Carbon::parse($saved['as_of'] ?? $record->created_at)->format('d M Y, h:i A'),
                'analysis' => $saved,
            ];
        }

        if ($saved === null || $record->isPending()) {
            $blocks[] = [
                'title' => 'As on now · '.now()->format('d M Y, h:i A'),
                'analysis' => app(CashbackAnalysisService::class)->forForm($record),
            ];
        }

        return [
            'blocks' => $blocks,
            'note' => $withSaved && $saved === null
                ? 'This form was raised before analyses were saved on forms, so only today\'s figures are shown.'
                : null,
        ];
    }

    /** "Returned 21 Nov 2026 by UPI (UTR …) · asked by … · approved by … : note" */
    protected static function returnTrail(CustomerCashbackRequest $record): string
    {
        return collect([
            'Returned '.$record->return_date?->format('d M Y').' by '.($record->return_mode?->label() ?? '—').(filled($record->return_reference) ? ' (UTR '.$record->return_reference.')' : ''),
            'Asked by '.($record->returnRequester?->name ?? '—').' · '.$record->return_requested_at?->format('d M Y, h:i A'),
            $record->return_reviewed_at !== null
                ? ($record->isReturned() ? 'Approved' : 'Rejected').' by '.($record->returnReviewer?->name ?? '—').' · '.$record->return_reviewed_at->format('d M Y, h:i A').(filled($record->return_review_note) ? ' — '.$record->return_review_note : '')
                : null,
        ])->filter()->implode(' · ');
    }

    /** "Waiting · due 22 Nov, 4:30 PM" / "Approved by …" / "Not approved within 24 h — moved to Admin …" */
    protected static function supervisorState(CustomerCashbackRequest $record): ?string
    {
        if ($record->supervisor_employee_id === null) {
            return null;
        }

        return match (true) {
            $record->escalated_at !== null => 'Not approved within '.CustomerCashbackService::SUPERVISOR_TAT_HOURS.' hours — moved to the Admin on '.$record->escalated_at->format('d M Y, h:i A'),
            $record->manager_approved_at !== null => 'Approved by '.($record->managerApprover?->name ?? '—').' on '.$record->manager_approved_at->format('d M Y, h:i A'),
            $record->status === CashbackRequestStatus::PendingManager => $record->supervisorTatLabel().' ('.$record->supervisor_due_at?->format('d M Y, h:i A').')',
            $record->status === CashbackRequestStatus::Rejected && $record->manager_approved_at === null => 'Rejected at this stage',
            default => null,
        };
    }

    /**
     * The form's activity log, newest first, in plain words.
     *
     * @return list<array{when: string, who: string, what: string}>
     */
    protected static function history(CustomerCashbackRequest $record): array
    {
        return Activity::query()
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->getKey())
            ->with('causer')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Activity $activity): array => [
                'when' => $activity->created_at?->format('d M Y, h:i A') ?? '—',
                'who' => $activity->causer?->name ?? 'System',
                'what' => self::describe($activity),
            ])
            ->all();
    }

    protected static function describe(Activity $activity): string
    {
        $properties = collect($activity->properties ?? []);

        if ($properties->has('reason') && $properties->has('new_amount')) {
            return sprintf('Amount changed from ₹%s to ₹%s — %s', indianNumberFormat($properties['old_amount']), indianNumberFormat($properties['new_amount']), $properties['reason']);
        }

        $changes = collect(data_get($activity->attribute_changes, 'attributes', []))
            ->except(['updated_at', 'created_at', 'id'])
            ->map(fn ($value, string $field): string => str($field)->headline().': '.(is_scalar($value) ? $value : json_encode($value)))
            ->implode(' · ');

        return trim(str((string) $activity->event)->headline().($changes !== '' ? ' — '.$changes : ''));
    }
}
