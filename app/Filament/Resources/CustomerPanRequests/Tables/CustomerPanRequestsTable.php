<?php

namespace App\Filament\Resources\CustomerPanRequests\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use App\Models\CustomerPanRequest;
use App\Filament\Resources\Customers\CustomerResource;








use Filament\Actions\ViewAction;


class CustomerPanRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                //
                TextColumn::make('request_no')
                    ->searchable(),

                TextColumn::make('customer.customer_name')
                    ->label('Customer')
                    ->searchable(),

                TextColumn::make('customer.application_no')
                    ->label('Application No')
                    ->searchable(),

                TextColumn::make('requestedBy.emp_name')
                    ->label('Requested By'),

                TextColumn::make('requestedBank.bank_name')
                    ->label('Requested Bank'),

                TextColumn::make('requested_loan_type')
                    ->badge(),

                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ]),

                TextColumn::make('created_at')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),
            ])
            ->filters([
                //

                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->recordActions([
                // EditAction::make(),
                ViewAction::make(),
                // EditAction::make(),
                Action::make('approve')
                    ->label('Approve / Reject')
                    ->icon('heroicon-o-check-circle')
                    ->color('warning')
                    ->visible(fn(CustomerPanRequest $record) => $record->status === CustomerPanRequest::STATUS_PENDING)
                    ->form([
                        Select::make('status')
                            ->label('Decision')
                            ->options([
                                CustomerPanRequest::STATUS_APPROVED => 'Approve',
                                CustomerPanRequest::STATUS_REJECTED => 'Reject',
                            ])
                            ->required(),

                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->rows(3),
                    ])
                    ->action(function (CustomerPanRequest $record, array $data) {

                        $record->update([
                            'status'      => $data['status'],
                            'remarks'     => $data['remarks'],
                            'approved_by' => auth()->user()->employee->id,
                            'approved_at' => now(),
                        ]);

                        // Notification::make()
                        //     ->title('Request Updated Successfully')
                        //     ->success()
                        //     ->send();

                        if ($data['status'] === CustomerPanRequest::STATUS_APPROVED) {

                            Notification::make()
                                ->title('Duplicate PAN Request Approved')
                                ->body('Click Continue to create the application.')
                                ->success()
                                ->actions([
                                    Action::make('continue')
                                        ->label('Continue')
                                        ->url(
                                            CustomerResource::getUrl(
                                                'continue-pan-request',
                                                [
                                                    'request' => $record->id,
                                                ]
                                            )
                                        ),
                                ])
                                ->sendToDatabase($record->requestedBy->user);
                        }
                    }),

                // Action::make('continue')
                //     ->label('Continue')
                //     ->icon('heroicon-o-arrow-right')
                //     ->color('success')
                //     ->visible(
                //         fn(CustomerPanRequest $record) =>
                //         $record->status === CustomerPanRequest::STATUS_APPROVED
                //     )
                //     ->url(
                //         fn(CustomerPanRequest $record) =>
                //         CustomerResource::getUrl(
                //             'continue-pan-request',
                //             [
                //                 'request' => $record,
                //             ]
                //         )
                //     ),

                Action::make('continue')
                    ->label('Continue')
                    ->icon('heroicon-o-arrow-right')
                    ->color('success')
                    ->visible(
                        fn(CustomerPanRequest $record) =>
                        $record->status === CustomerPanRequest::STATUS_APPROVED
                    )
                    ->url(
                        fn(CustomerPanRequest $record) =>
                        CustomerResource::getUrl('create', [
                            'pan_request' => $record->id,
                        ])
                    ),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
