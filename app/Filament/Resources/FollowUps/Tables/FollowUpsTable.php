<?php

namespace App\Filament\Resources\FollowUps\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;

use App\Filament\Resources\FollowUps\FollowUpResource;


use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;


class FollowUpsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([


                TextColumn::make('customer.customer_name')
                    ->label('Customer')
                    ->searchable(),

                TextColumn::make('customer.mobile_no')
                    ->label('Mobile'),



                TextColumn::make('follow_up_date')
                    ->date(),

                TextColumn::make('follow_up_date')
                    ->label('Follow Up Created')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),

                TextColumn::make('follow_up_type')
                    ->badge(),

                TextColumn::make('status')
                    ->badge(),

                TextColumn::make('bank.bank_name')
                    ->label('Bank')
                    ->searchable()
                    ->sortable(),

                // TextColumn::make('next_follow_up_date')
                //     ->date(),

                TextColumn::make('next_follow_up_date')
                    ->label('Next Follow Up')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),

                TextColumn::make('employee.emp_name')
                    ->label('Followed By'),

                TextColumn::make('created_at')
                    ->label('Created On')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),

                // TextColumn::make('created_at')
                //     ->dateTime()

                //

                // Action::make('follow_up')
                // ->label('Follow Up')
                // ->icon('heroicon-o-phone')
                // ->color('primary')
                // ->url(fn ($record) => FollowUpResource::getUrl('create', [
                // 'customer' => $record->id,
                // ]))

            ])
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25, 50, 100, 'all'])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                //   Action::make('followup')
                //     ->label('Follow Up')
                //     ->icon('heroicon-o-phone')
                //     ->color('warning')
                //     ->url(fn ($record) => FollowUpResource::getUrl('create', [
                //         'customer' => $record->id,
                //     ])),

                Action::make('followup')
                    ->label('Follow Up')
                    ->icon('heroicon-o-phone')
                    ->color('warning')
                    ->url(fn($record) => FollowUpResource::getUrl('create', [
                        'customer' => $record->customer_id,
                    ])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
