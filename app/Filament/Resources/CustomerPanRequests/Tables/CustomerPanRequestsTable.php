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

use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;


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

                TextColumn::make('pan_number')
                    ->searchable(),

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

                Filter::make('pan_number')
                    ->form([
                        TextInput::make('pan_number')
                            ->label('PAN Number')
                            ->placeholder('ABCDE1234F')
                            ->maxLength(10)
                            ->dehydrateStateUsing(
                                fn(?string $state): ?string =>
                                filled($state) ? strtoupper($state) : null
                            ),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['pan_number'] ?? null),
                            fn(Builder $query) =>
                            $query->where(
                                'pan_number',
                                'like',
                                '%' . strtoupper($data['pan_number']) . '%'
                            )
                        );
                    }),
            ])
            ->recordActions([
                // EditAction::make(),
                ViewAction::make(),

                Action::make('approve')
                    ->label('Approve / Reject')
                    ->icon('heroicon-o-check-circle')
                    ->color('warning')
                    ->visible(
                        fn(CustomerPanRequest $record): bool =>
                        $record->status === CustomerPanRequest::STATUS_PENDING
                            && auth()->user()->hasRole('Admin')
                    )
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

                        $employee = auth()->user()->employee;

                        $record->update([
                            'status'      => $data['status'],
                            'remarks'     => $data['remarks'] ?? null,
                            'approved_by' => $employee?->id,
                            'approved_at' => now(),
                        ]);

                        /*
                        |--------------------------------------------------------------------------
                        | APPROVED
                        |--------------------------------------------------------------------------
                        */

                        if ($data['status'] === CustomerPanRequest::STATUS_APPROVED) {

                            Notification::make()
                                ->title('Duplicate PAN Request Approved')
                                ->body('Your request has been approved. Click Continue to create the application.')
                                ->success()
                                ->actions([

                                    // Action::make('continue')
                                    //     ->label('Continue')
                                    //     ->url(
                                    //         CustomerResource::getUrl(
                                    //             'continue-pan-request',
                                    //             [
                                    //                 'request' => $record->id,
                                    //             ]
                                    //         )
                                    //     ),

                                    // Action::make('continue')
                                    //     ->label('Continue')
                                    //     ->icon('heroicon-o-arrow-right')
                                    //     ->color('success')
                                    //     ->visible(
                                    //         fn(CustomerPanRequest $record): bool =>
                                    //         $record->status === CustomerPanRequest::STATUS_APPROVED
                                    //             && blank($record->application_id)
                                    //             && ! auth()->user()->hasRole('Admin')
                                    //             && auth()->user()->employee?->id === $record->requested_by
                                    //     )
                                    //     ->url(
                                    //         fn(CustomerPanRequest $record) =>
                                    //         CustomerResource::getUrl('create', [
                                    //             'pan_request' => $record->id,
                                    //         ])
                                    //     ),

                                    Action::make('continue')
                                        ->label('Continue')
                                        ->icon('heroicon-o-arrow-right')
                                        ->color('success'),
                                ])
                                ->sendToDatabase(
                                    $record->requestedBy?->user
                                );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | REJECTED
                        |--------------------------------------------------------------------------
                        */

                        if ($data['status'] === CustomerPanRequest::STATUS_REJECTED) {

                            Notification::make()
                                ->title('Duplicate PAN Request Rejected')
                                ->body(
                                    filled($record->remarks)
                                        ? 'Reason: ' . $record->remarks
                                        : 'Your duplicate PAN request has been rejected.'
                                )
                                ->danger()
                                ->sendToDatabase(
                                    $record->requestedBy?->user
                                );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | ADMIN CONFIRMATION
                        |--------------------------------------------------------------------------
                        */

                        Notification::make()
                            ->title(
                                $data['status'] === CustomerPanRequest::STATUS_APPROVED
                                    ? 'Request Approved'
                                    : 'Request Rejected'
                            )
                            ->success()
                            ->send();
                    }),
                Action::make('continue')
                    ->label('Continue')
                    ->icon('heroicon-o-arrow-right')
                    ->color('success')
                    // ->visible(
                    //     fn(CustomerPanRequest $record): bool =>
                    //     $record->status === CustomerPanRequest::STATUS_APPROVED
                    //         && blank($record->application_id)
                    // )
                    ->visible(
                        fn(CustomerPanRequest $record): bool =>
                        $record->status === CustomerPanRequest::STATUS_APPROVED
                            && blank($record->application_id)
                            && ! auth()->user()->hasRole('Admin')
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
