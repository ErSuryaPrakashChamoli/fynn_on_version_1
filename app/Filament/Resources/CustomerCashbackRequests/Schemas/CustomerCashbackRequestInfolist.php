<?php

namespace App\Filament\Resources\CustomerCashbackRequests\Schemas;

use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackRequestStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CustomerCashbackRequest;
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
                    ]),

                Section::make('Voucher')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->columnSpanFull()
                    ->schema([
                        View::make('filament.components.cashback-voucher')
                            ->viewData(fn (CustomerCashbackRequest $record): array => [
                                'voucher' => $record->loadMissing(['customer', 'aro', 'teamLeader', 'manager', 'requester', 'managerApprover', 'approver', 'payer']),
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
