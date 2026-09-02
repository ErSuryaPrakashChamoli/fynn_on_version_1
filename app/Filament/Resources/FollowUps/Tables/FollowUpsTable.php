<?php

namespace App\Filament\Resources\FollowUps\Tables;

use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Support\SelectedMonth;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class FollowUpsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('next_follow_up_date', 'asc')
            ->columns([

                TextColumn::make('display_name')
                    ->label('Customer'),

                TextColumn::make('customer.mobile_no')
                    ->label('Mobile')
                    ->placeholder('-'),

                TextColumn::make('follow_up_date')
                    ->date(),

                TextColumn::make('follow_up_date')
                    ->label('Follow Up Created')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),

                TextColumn::make('follow_up_type')
                    ->badge(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'Interested' => 'success',
                        'Pending' => 'warning',
                        'Eligible for Other Bank' => 'info',
                        'Not Interested', 'Not Eligible' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('bank.bank_name')
                    ->label('Bank')
                    ->searchable()
                    ->sortable(),

                // TextColumn::make('next_follow_up_date')
                //     ->date(),

                TextColumn::make('next_follow_up_date')
                    ->label('Next Follow Up')
                    ->dateTime('d M Y h:i A')
                    ->sortable()
                    ->badge()
                    ->color(function (?string $state): string {
                        if (blank($state)) {
                            return 'gray';
                        }

                        $date = Carbon::parse($state);

                        if ($date->isPast() && ! $date->isToday()) {
                            return 'danger';
                        }

                        return $date->isToday() ? 'warning' : 'gray';
                    }),

                TextColumn::make('employee.emp_name')
                    ->label('Followed By')
                    ->placeholder('Admin'),

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
            ->modifyQueryUsing(
                fn (Builder $query) => $query->whereBetween('follow_up_date', SelectedMonth::range())
            )
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
                    ->url(fn ($record) => FollowUpResource::getUrl('create', filled($record->customer_id)
                        ? ['customer' => $record->customer_id]
                        : ['ai_customer_record' => $record->ai_customer_record_id])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
