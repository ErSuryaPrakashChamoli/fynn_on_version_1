<?php

namespace App\Filament\Resources\Customers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use App\Models\Employee;
use App\Filament\Resources\FollowUps\FollowUpResource;
use Filament\Actions\Action;
use App\Filament\Exports\CustomerExporter;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ImportAction;
use App\Filament\Imports\CustomerImporter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                //
                TextColumn::make('application_no')
                    ->label('Application No')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('lan_no')
                    ->label('LAN No')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Customer Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('sanctioned_loan_amount')
                    ->label('Loan Amount')
                    ->formatStateUsing(fn($state) => filled($state) ? '₹' . number_format((float) $state, 0) : '-')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('mobile_no')
                    ->label('Mobile No')
                    ->formatStateUsing(function (?string $state): string {
                        if (blank($state) || strlen($state) < 4) {
                            return $state ?? '-';
                        }

                        return substr($state, 0, 4) . 'XXXXXX';
                    })
                    ->searchable(),

                TextColumn::make('email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('loan_applied')
                    ->label('Loan Applied')
                    ->searchable(),

                TextColumn::make('salary')
                    ->label('Salary')
                    ->formatStateUsing(fn($state) => filled($state) ? '₹' . number_format((float) $state, 0) : '-')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('eligibility_status')
                    ->label('Eligibility')
                    ->badge(),

                TextColumn::make('bank_eligible_for')
                    ->label('Bank Eligible For')
                    ->formatStateUsing(function ($state, $record) {
                        return strtolower((string) $state) === 'other'
                            ? ($record->other_bank_eligible_for ?: '-')
                            : $state;
                    })
                    ->searchable(),

                TextColumn::make('journey_status')
                    ->label('Journey')
                    ->badge(),

                TextColumn::make('sanctioned_bank')
                    ->label('Bank')
                    ->searchable(),

                TextColumn::make('channel')
                    ->label('Channel')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->filters([

                SelectFilter::make('journey_status')
                    ->label('Journey Status')
                    ->options([
                        'sfl' => 'SFL',
                        'underwriting' => 'Underwriting',
                        'approved' => 'Approved',
                        'sanctioned' => 'Sanctioned',
                        'disbursal_documents' => 'Disbursal Documents',
                        'completed' => 'Completed',
                        'carry_forward' => 'Carry Forward',
                        'dropped' => 'Dropped',
                        'not_approved' => 'Not Approved',
                    ])
                    ->searchable()
                    ->preload(),

                Filter::make('created_at')
                    ->label('Created Date')
                    ->form([
                        DatePicker::make('created_from')
                            ->label('From'),

                        DatePicker::make('created_until')
                            ->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn(Builder $query, $date) => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn(Builder $query, $date) => $query->whereDate('created_at', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['created_from'] ?? null) {
                            $indicators[] = 'From: ' . \Carbon\Carbon::parse($data['created_from'])->format('d M Y');
                        }

                        if ($data['created_until'] ?? null) {
                            $indicators[] = 'To: ' . \Carbon\Carbon::parse($data['created_until'])->format('d M Y');
                        }

                        return $indicators;
                    }),




            ])
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25, 50, 100, 'all'])
            ->recordActions([
                ViewAction::make(),
                // EditAction::make(),
                EditAction::make()
                    ->visible(
                        fn($record) =>
                        // ! $record->documents_submitted &&
                        // auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER
                        Filament::auth()->user()?->employee?->designation !== Employee::DESIGNATION_CALLER
                    ),

                Action::make('followup')
                    ->label('Follow Up')
                    ->icon('heroicon-o-phone')
                    ->color('warning')
                    ->url(fn($record) => FollowUpResource::getUrl('create', [
                        'customer' => $record->id,
                    ])),
            ])
            ->headerActions([

                ExportAction::make()
                    ->exporter(CustomerExporter::class)
                    ->label('Export Customers')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn() => auth()->user()->hasRole('Admin')),

                ImportAction::make()
                    ->label('Import Customers')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->importer(CustomerImporter::class)
            ])
            ->toolbarActions([
                // BulkActionGroup::make([
                // DeleteBulkAction::make(),
                // ]),

                DeleteBulkAction::make()
                    ->visible(fn() => auth()->user()->hasRole('Admin')),
                // ->visible(
                //     fn() => auth()->user()->employee?->designation === Employee::DESIGNATION_ADMIN
                // ),
                ExportBulkAction::make()
                    ->exporter(CustomerExporter::class)
                    ->label('Export Selected')
                    ->visible(fn() => auth()->user()->hasRole('Admin')),
                // ->visible(
                //     fn() => auth()->user()->employee?->designation === Employee::DESIGNATION_ADMIN
                // ),


            ]);
    }
}
