<?php

namespace App\Filament\Resources\CustomerCashbackRequests\Schemas;

use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackRequestStatus;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestActions;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CustomerCashbackRequest;
use App\Services\CustomerCashbackService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\Activitylog\Models\Activity;

/**
 * The voucher, laid out like the paper Acknowledgment form, then who did
 * what (approvals, rejection, retrieval, payment) and the change history.
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
                                'voucher' => $record->loadMissing(['customer', 'aro', 'teamLeader', 'manager', 'clusterManager', 'requester', 'managerApprover', 'approver', 'payer', 'original']),
                            ]),
                    ]),

                Section::make('Payments on this cashback')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->description('The original form and every revision for a further payment, each with its own UTR.')
                    ->columnSpanFull()
                    ->visible(fn (CustomerCashbackRequest $record): bool => $record->isRevision() || $record->revisions()->exists())
                    ->schema([
                        RepeatableEntry::make('payments')
                            ->hiddenLabel()
                            ->state(fn (CustomerCashbackRequest $record): array => self::payments($record))
                            ->schema([
                                TextEntry::make('voucher')->label('Voucher')->weight('bold'),
                                TextEntry::make('amount')->label('Amount'),
                                TextEntry::make('approval')->label('Approval'),
                                TextEntry::make('payment')->label('Payment'),
                                TextEntry::make('utr')->label('UTR / Transaction ID'),
                                TextEntry::make('paid_on')->label('Paid on'),
                            ])
                            ->columns(6),
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
     * The original form and its revisions, each with its payment.
     *
     * @return list<array{voucher: string, amount: string, approval: string, payment: string, utr: string, paid_on: string}>
     */
    protected static function payments(CustomerCashbackRequest $record): array
    {
        $original = $record->isRevision() ? $record->original : $record;

        if ($original === null) {
            return [];
        }

        return collect([$original])
            ->merge($original->revisions()->get())
            ->map(fn (CustomerCashbackRequest $form): array => [
                'voucher' => (string) $form->voucher_no,
                'amount' => '₹'.indianNumberFormat($form->effectiveAmount()),
                'approval' => $form->status->label(),
                'payment' => $form->payment_status->label(),
                'utr' => $form->transaction_id ?? '—',
                'paid_on' => $form->payment_date?->format('d M Y') ?? '—',
            ])
            ->values()
            ->all();
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
