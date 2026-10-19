<?php

namespace App\Filament\Resources\CibilRequests\Tables;

use App\Enums\CibilFailureCategory;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Models\CibilReport;
use App\Support\CibilAccess;
use App\Support\CibilMask;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * One row per request. Shows who asked for what and how it went — never
 * the report or any part of the bureau's answer.
 */
class CibilRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (CibilReport $record): string => CibilReportResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('customer_name')
                    ->label('Customer / lead')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (CibilReport $record): string => trim(($record->subjectType() ?? 'Not linked').' · '.CibilMask::pan($record->pan_number))),

                TextColumn::make('bureau')
                    ->label('Bureau')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (CreditBureau $state): string => $state->label())
                    ->color(fn (CreditBureau $state): string => $state->color()),

                TextColumn::make('request_type')
                    ->label('Request')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (CibilRequestType $state): string => $state->label())
                    ->color(fn (CibilRequestType $state): string => $state->color()),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (CibilReportStatus $state): string => $state->label())
                    ->color(fn (CibilReportStatus $state): string => $state->color())
                    ->description(fn (CibilReport $record): ?string => $record->resolved_at ? 'Verified '.$record->resolved_at->format('d M Y') : null),

                TextColumn::make('requester.name')
                    ->label('Requested by')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Former user')
                    ->description(fn (CibilReport $record): ?string => $record->requester?->employee?->emp_id),

                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),

                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('d M Y, h:i A')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('provider_status_code')
                    ->label('Provider response')
                    ->sortable()
                    ->formatStateUsing(fn (int $state, CibilReport $record): string => trim("HTTP {$state} ".($record->provider_message_code ?? '')))
                    ->placeholder('—'),

                TextColumn::make('duration_ms')
                    ->label('Provider time')
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => $state >= 1000 ? round($state / 1000, 1).' s' : "{$state} ms")
                    ->placeholder('—'),

                TextColumn::make('failure_category')
                    ->label('Failure type')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (CibilFailureCategory $state): string => $state->label())
                    ->color(fn (CibilFailureCategory $state): string => $state->color())
                    ->placeholder('—'),

                TextColumn::make('provider_contact')
                    ->label('Reached provider')
                    ->state(fn (CibilReport $record): string => $record->providerContact())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Answered' => 'success',
                        'Unknown' => 'warning',
                        default => 'gray',
                    })
                    ->tooltip('Whether the request reached the provider: "Unknown" means it may have been processed (and billed) without an answer reaching the app.')
                    ->toggleable(),

                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(80)
                    ->tooltip(fn (CibilReport $record): ?string => $record->error_message)
                    ->wrap()
                    ->placeholder('—'),

                TextColumn::make('bureau_reference')
                    ->label('Provider reference')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => CibilAccess::canViewAudit(Filament::auth()->user())),

                TextColumn::make('uuid')
                    ->label('Request ID')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => CibilAccess::canViewAudit(Filament::auth()->user())),
            ])
            ->filters([
                SelectFilter::make('bureau')
                    ->label('Bureau')
                    ->options(CreditBureau::options()),

                SelectFilter::make('request_type')
                    ->label('Request')
                    ->options(CibilRequestType::options()),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(CibilReportStatus::options()),

                SelectFilter::make('failure_category')
                    ->label('Failure type')
                    ->options(CibilFailureCategory::options()),

                SelectFilter::make('fetched_for')
                    ->label('Fetched for')
                    ->options(['customer' => 'Customer', 'lead' => 'Lead (My Leads)', 'assigned' => 'Assigned lead'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'customer' => $query->whereNotNull('customer_id')->whereNull('lead_id')->whereNull('customer_assignment_id'),
                        'lead' => $query->whereNotNull('lead_id'),
                        'assigned' => $query->whereNotNull('customer_assignment_id'),
                        default => $query,
                    }),

                // Lists every login, so only for the Admin (as on CIBIL Reports).
                SelectFilter::make('requested_by')
                    ->label('Requested by')
                    ->relationship('requester', 'name')
                    ->preload()
                    ->visible(fn (): bool => CibilAccess::canManage(Filament::auth()->user())),

                Filter::make('requested_between')
                    ->schema([
                        DatePicker::make('from')->label('Requested from'),
                        DatePicker::make('until')->label('Requested until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-eye')
                    ->url(fn (CibilReport $record): string => CibilReportResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
