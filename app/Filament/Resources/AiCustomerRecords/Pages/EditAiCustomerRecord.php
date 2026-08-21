<?php

namespace App\Filament\Resources\AiCustomerRecords\Pages;

use App\Filament\Resources\AiCustomerRecords\AiCustomerRecordResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAiCustomerRecord extends EditRecord
{
    protected static string $resource = AiCustomerRecordResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {


        /*
         * The Customer Data fields are stored inside
         * the AiCustomerRecord "data" JSON column.
         *
         * Filament submits the edited values as:
         *
         * data.customer_name
         * data.mobile_number
         * data.product_type
         * etc.
         *
         * Returning $data here ensures Filament persists
         * those changes back into the JSON column.
         */


        $currentData = $this->record->data ?? [];

        // dd($currentData);

        // dd([
        //     'record_data' => $this->record->data,
        //     'form_state' => $this->form->getState(),
        // ]);

        $data['data'] = array_merge(
            $currentData,
            $data['data'] ?? []
        );
        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve Data')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->action(function (): void {

                    // Validate the CURRENT saved record.
                    $record = $this->record->fresh('schema');

                    $errors = [];

                    foreach ($record->schema?->getFieldDefinitions() ?? [] as $field) {
                        $key = (string) ($field['key'] ?? '');
                        $label = (string) ($field['label'] ?? $key);
                        $type = (string) ($field['type'] ?? 'text');

                        if ($key === '') {
                            continue;
                        }

                        $value = $record->data[$key] ?? null;

                        if (($field['required'] ?? false) && blank($value)) {
                            $errors[] = "{$label} is required.";
                            continue;
                        }

                        if (blank($value)) {
                            continue;
                        }

                        if (
                            $type === 'mobile'
                            && ! preg_match(
                                '/^[6-9][0-9]{9}$/',
                                (string) $value
                            )
                        ) {
                            $errors[] = "{$label} must be a valid 10-digit mobile number.";
                        }

                        if (
                            $type === 'email'
                            && ! filter_var($value, FILTER_VALIDATE_EMAIL)
                        ) {
                            $errors[] = "{$label} must be a valid email address.";
                        }

                        if (
                            in_array($type, ['number', 'decimal'], true)
                            && ! is_numeric($value)
                        ) {
                            $errors[] = "{$label} must be a valid number.";
                        }
                    }

                    if ($errors !== []) {
                        Notification::make()
                            ->title('Validation failed')
                            ->body(implode(' ', $errors))
                            ->danger()
                            ->send();

                        return;
                    }

                    $record->update([
                        'status' => 'approved',
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now(),
                        'rejection_reason' => null,
                    ]);

                    Notification::make()
                        ->title('AI customer data approved')
                        ->success()
                        ->send();
                }),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->form([
                    Textarea::make('reason')
                        ->label('Rejection Reason')
                        ->required()
                        ->minLength(5)
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {

                    $this->record->update([
                        'status' => 'rejected',
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now(),
                        'rejection_reason' => $data['reason'],
                    ]);

                    Notification::make()
                        ->title('AI customer data rejected')
                        ->danger()
                        ->send();
                }),
        ];
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->form->fill([
            ...($this->record->data ?? []),
        ]);
    }
}
