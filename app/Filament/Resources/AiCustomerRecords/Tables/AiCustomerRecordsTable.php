<?php

namespace App\Filament\Resources\AiCustomerRecords\Tables;

use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
// use Filament\Actions\BulkActionGroup;
// use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

class AiCustomerRecordsTable
{
    public static function configure(Table $table): Table
    {
        $dynamicColumns = [];

        try {
            $fields = AiDocumentSchema::query()->get()
                ->flatMap(fn(AiDocumentSchema $schema) => $schema->getFieldDefinitions())
                ->filter(fn($field) => filled($field['key'] ?? null))
                ->unique('key')
                ->values();

            foreach ($fields as $field) {
                $key = (string) $field['key'];
                $label = (string) ($field['label'] ?? $key);
                $dynamicColumns[] = TextColumn::make("data.$key")
                    ->label($label)
                    ->searchable()
                    ->toggleable();
            }
        } catch (Throwable) {
            // During the first deployment the schema table may not exist yet.
        }

        return $table
            ->defaultSort('id', 'desc')
            ->columns(array_merge([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('schema.name')->label('Configuration')->searchable()->sortable(),
                TextColumn::make('customer.customer_name')->label('Customer')->searchable()->sortable()->default('-'),
                TextColumn::make('document.original_name')->label('Source Document')->limit(30),
                TextColumn::make('status')->badge()->color(fn(string $state): string => match ($state) {
                    'approved' => 'success',
                    'review' => 'warning',
                    'rejected' => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('confidence_score')
                    ->label('Confidence')
                    ->formatStateUsing(fn($state) => $state === null ? '-' : number_format((float) $state * 100, 1) . '%'),
            ], $dynamicColumns))
            ->filters([
                SelectFilter::make('schema_id')->label('Configuration')->relationship('schema', 'name')->searchable()->preload(),
                SelectFilter::make('status')->options([
                    'review' => 'Review',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'pending' => 'Pending',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approve')
                        ->label('Approve Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records = $records->filter(
                                fn(AiCustomerRecord $record) => $record->status === 'review'
                            );

                            if ($records->isEmpty()) {
                                Notification::make()
                                    ->title('No records available for approval')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $now = now();

                            foreach ($records as $record) {
                                $record->update([
                                    'status' => 'approved',
                                    'reviewed_by' => auth()->id(),
                                    'reviewed_at' => $now,
                                    'rejection_reason' => null,
                                ]);
                            }

                            Notification::make()
                                ->title("{$records->count()} records approved")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('reject')
                        ->label('Reject Selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->form([
                            \Filament\Forms\Components\Textarea::make('reason')
                                ->label('Rejection Reason')
                                ->required()
                                ->minLength(5)
                                ->maxLength(1000),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records = $records->filter(
                                fn(AiCustomerRecord $record) => $record->status === 'review'
                            );

                            if ($records->isEmpty()) {
                                Notification::make()
                                    ->title('No records available for rejection')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $now = now();

                            foreach ($records as $record) {
                                $record->update([
                                    'status' => 'rejected',
                                    'reviewed_by' => auth()->id(),
                                    'reviewed_at' => $now,
                                    'rejection_reason' => $data['reason'],
                                ]);
                            }

                            Notification::make()
                                ->title("{$records->count()} records rejected")
                                ->danger()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
