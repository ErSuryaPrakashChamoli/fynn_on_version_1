<?php

namespace App\Filament\Resources\CibilReports\Tables;

use App\Enums\BureauDecision;
use App\Enums\CibilPdfStatus;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CibilScoreBand;
use App\Enums\CreditBureau;
use App\Models\CibilReport;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use App\Support\CibilMask;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CibilReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => self::onePerApplicant($query))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (CibilReport $record): ?string => $record->maskedMobile()),

                // Masked on screen; typing the full PAN in the search still finds it.
                TextColumn::make('pan_number')
                    ->label('PAN')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => CibilMask::pan($state)),

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

                TextColumn::make('fetched_for')
                    ->label('For')
                    ->badge()
                    ->color('gray')
                    ->state(fn (CibilReport $record): ?string => $record->subjectType())
                    ->placeholder('—'),

                TextColumn::make('score')
                    ->label('Score')
                    ->sortable()
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => $state < 300 ? 'NH' : (string) $state)
                    ->color(fn (CibilReport $record): string => $record->scoreBand()?->color() ?? 'gray')
                    ->description(fn (CibilReport $record): ?string => $record->scoreBand()?->label()),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (CibilReportStatus $state): string => $state->label())
                    ->color(fn (CibilReportStatus $state): string => $state->color())
                    ->tooltip(fn (CibilReport $record): ?string => $record->error_message),

                TextColumn::make('bureau_decision')
                    ->label('Decision')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (BureauDecision $state): string => $state->label())
                    ->color(fn (BureauDecision $state): string => $state->color())
                    ->placeholder(fn (CibilReport $record): string => $record->isForLead() && $record->status === CibilReportStatus::Success ? 'Awaiting decision' : '—'),

                TextColumn::make('fetch_count')
                    ->label('Times fetched')
                    ->badge()
                    ->color('info')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('fetch_count', $direction)),

                TextColumn::make('pdf_status')
                    ->label('PDF')
                    ->badge()
                    ->formatStateUsing(fn (CibilPdfStatus $state): string => $state->label())
                    ->color(fn (CibilPdfStatus $state): string => $state->color())
                    ->placeholder('—'),

                TextColumn::make('bureau_reference')
                    ->label('Provider reference')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => CibilAccess::canViewAudit(Filament::auth()->user())),

                TextColumn::make('requester.name')
                    ->label('Fetched by')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Former user')
                    ->description(fn (CibilReport $record): ?string => $record->requester?->employee?->emp_id),

                TextColumn::make('created_at')
                    ->label('Fetched on')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(CibilReportStatus::options()),

                SelectFilter::make('bureau')
                    ->label('Bureau')
                    ->options(CreditBureau::options()),

                SelectFilter::make('request_type')
                    ->label('Request')
                    ->options(CibilRequestType::options()),

                SelectFilter::make('score_band')
                    ->label('Score band')
                    ->options(CibilScoreBand::options())
                    ->query(function (Builder $query, array $data): Builder {
                        $band = CibilScoreBand::tryFrom((string) ($data['value'] ?? ''));

                        return $band
                            ? $query->whereBetween('score', $band->range())
                            : $query;
                    }),

                SelectFilter::make('bureau_decision')
                    ->label('Decision')
                    ->options(BureauDecision::options()),

                SelectFilter::make('requested_by')
                    ->label('Fetched by')
                    ->relationship('requester', 'name')
                    ->preload()
                    ->visible(fn (): bool => self::viewerManages()),

                Filter::make('fetched_between')
                    ->schema([
                        DatePicker::make('from')->label('Fetched from'),
                        DatePicker::make('until')->label('Fetched until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('openPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn (CibilReport $record): bool => self::viewerCanOpenPdf($record))
                    ->url(fn (CibilReport $record): ?string => $record->pdfUrl())
                    ->openUrlInNewTab(),
            ]);
    }

    /**
     * One row per applicant: their newest fetched report (or newest attempt)
     * with the number of pulls — CibilReportService::latestPerApplicant().
     *
     * @param  Builder<CibilReport>  $query
     * @return Builder<CibilReport>
     */
    protected static function onePerApplicant(Builder $query): Builder
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            ? app(CibilReportService::class)->latestPerApplicant($query, $user)
            : $query->whereRaw('1 = 0');
    }

    protected static function viewerManages(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && CibilAccess::canManage($user);
    }

    protected static function viewerCanOpenPdf(CibilReport $record): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(CibilReportService::class)->canDownloadPdf($record, $user);
    }
}
