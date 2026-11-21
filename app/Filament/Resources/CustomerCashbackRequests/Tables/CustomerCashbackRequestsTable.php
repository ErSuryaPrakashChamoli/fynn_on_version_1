<?php

namespace App\Filament\Resources\CustomerCashbackRequests\Tables;

use App\Enums\CashbackPaymentMode;
use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackRequestStatus;
use App\Enums\CashbackType;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestActions;
use App\Models\CustomerCashbackRequest;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CustomerCashbackRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('voucher_no')
                    ->label('Voucher No')
                    ->weight('bold')
                    ->description(fn (CustomerCashbackRequest $record): ?string => $record->isRevision() ? 'Revision '.$record->revision_no.' — second payment' : null)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer.customer_name')
                    ->label('Customer')
                    ->description(fn (CustomerCashbackRequest $record): ?string => $record->customer?->application_no ?: $record->customer?->mobile_no)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Declaration')
                    ->badge()
                    ->formatStateUsing(fn (CashbackType $state): string => $state->label())
                    ->color(fn (CashbackType $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->state(fn (CustomerCashbackRequest $record): float => $record->effectiveAmount())
                    ->formatStateUsing(fn (float $state): string => '₹'.indianNumberFormat($state))
                    ->description(fn (CustomerCashbackRequest $record): ?string => $record->approved_amount !== null && (float) $record->approved_amount !== (float) $record->amount
                        ? 'Asked ₹'.indianNumberFormat($record->amount)
                        : null)
                    ->alignEnd()
                    ->sortable(query: fn ($query, string $direction) => $query->orderByRaw('COALESCE(approved_amount, amount) '.($direction === 'desc' ? 'desc' : 'asc'))),

                TextColumn::make('status')
                    ->label('Approval')
                    ->badge()
                    ->formatStateUsing(fn (CashbackRequestStatus $state): string => $state->label())
                    ->color(fn (CashbackRequestStatus $state): string => $state->color())
                    ->description(fn (CustomerCashbackRequest $record): ?string => $record->escalated_at !== null && $record->status === CashbackRequestStatus::PendingAdmin
                        ? 'Supervisor missed 24 h'
                        : $record->supervisorTatLabel())
                    ->sortable(),

                TextColumn::make('supervisor.emp_name')
                    ->label('Supervisor')
                    ->description(fn (CustomerCashbackRequest $record): ?string => $record->supervisor_due_at?->format('d M, h:i A'))
                    ->placeholder('—')
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn (CashbackPaymentStatus $state): string => $state->label())
                    ->color(fn (CashbackPaymentStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('payment_mode')
                    ->label('Mode')
                    ->formatStateUsing(fn (?CashbackPaymentMode $state): string => $state?->label() ?? '—')
                    ->placeholder('—')
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('aro.emp_name')
                    ->label('ARO')
                    ->description(fn (CustomerCashbackRequest $record): ?string => $record->aro?->emp_id)
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('teamLeader.emp_name')
                    ->label('Team Leader')
                    ->placeholder('—')
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('manager.emp_name')
                    ->label('Manager')
                    ->placeholder('—')
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('clusterManager.emp_name')
                    ->label('Cluster Manager')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                TextColumn::make('requester.name')
                    ->label('Filled by')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Issue date')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),

                TextColumn::make('transaction_id')
                    ->label('UTR / Transaction ID')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('payment_date')
                    ->label('Payment date')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Approval')
                    ->options(CashbackRequestStatus::options()),

                SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options(CashbackPaymentStatus::options()),

                SelectFilter::make('type')
                    ->label('Declaration')
                    ->options(CashbackType::formOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(CustomerCashbackRequestActions::all()),
            ]);
    }
}
