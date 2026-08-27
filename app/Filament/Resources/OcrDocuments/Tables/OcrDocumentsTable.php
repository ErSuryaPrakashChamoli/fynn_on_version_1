<?php

namespace App\Filament\Resources\OcrDocuments\Tables;

use App\Jobs\ProcessOcrDocument;
use App\Models\OcrDocument;
use App\Support\SelectedMonth;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OcrDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('customer.customer_name')->label('Customer')->searchable()->sortable(),
                TextColumn::make('original_name')->label('Document')->searchable()->limit(35),
                TextColumn::make('document_type')->label('Type')->badge(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'completed' => 'success',
                    'processing' => 'warning',
                    'failed' => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('page_count')->label('Pages')->sortable(),
                TextColumn::make('formatted_confidence')->label('Confidence'),
                TextColumn::make('is_verified')->label('Verified')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('created_at')->dateTime('d M Y h:i A')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'processing' => 'Processing',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                ]),
                SelectFilter::make('document_type')->options([
                    'customer_form' => 'Customer Form',
                    'kyc' => 'KYC',
                    'pan' => 'PAN',
                    'aadhaar' => 'Aadhaar',
                    'salary_slip' => 'Salary Slip',
                    'bank_statement' => 'Bank Statement',
                    'sanction_letter' => 'Sanction Letter',
                    'other' => 'Other',
                ]),
            ])
            ->modifyQueryUsing(
                fn (Builder $query) => $query->whereBetween('created_at', SelectedMonth::range())
            )
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('process')
                    // ->label('Process OCR')
                    ->label(
                        fn (OcrDocument $record): string => $record->status === 'completed'
                            ? 'Re-process OCR'
                            : 'Process OCR'
                    )
                    ->icon('heroicon-o-sparkles')
                    ->color('warning')
                    // ->visible(fn (OcrDocument $record): bool => in_array($record->status, ['pending', 'failed'], true))
                    ->visible(fn (OcrDocument $record): bool => in_array($record->status, ['pending', 'failed', 'completed'], true))
                    ->requiresConfirmation()
                    ->action(function (OcrDocument $record): void {
                        $record->update(['status' => 'pending', 'error_message' => null]);
                        ProcessOcrDocument::dispatchFor($record);
                        Notification::make()->title('OCR processing queued')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
