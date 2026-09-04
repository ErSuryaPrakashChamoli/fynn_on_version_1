<?php

namespace App\Filament\Resources\FollowUps\Tables;

use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Support\EmployeeOptions;
use App\Support\SelectedMonth;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                    ->label('Customer')
                    // Name comes from the customer, AI record or lead this
                    // follow-up hangs off, so sort by the customer name and
                    // fall back to the row order for the other two sources.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        Customer::query()
                            ->select('customer_name')
                            ->whereColumn('customers.id', 'follow_ups.customer_id')
                            ->limit(1),
                        $direction,
                    )),

                TextColumn::make('customer.mobile_no')
                    ->label('Mobile')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('follow_up_date')
                    ->label('Follow Up Created')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),

                TextColumn::make('follow_up_type')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
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
                    ->placeholder('Admin')
                    ->description(fn (FollowUp $record): ?string => $record->employee?->emp_id)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.emp_id')
                    ->label('Emp ID')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

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
                SelectFilter::make('employee_id')
                    ->label('Followed By')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::visibleTo()),

                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options([
                        'Pending' => 'Pending',
                        'Interested' => 'Interested',
                        'Not Interested' => 'Not Interested',
                        'Busy' => 'Busy',
                        'No Response' => 'No Response',
                        'Not Eligible' => 'Not Eligible',
                        'Eligible for Other Bank' => 'Eligible for Other Bank',
                    ]),

                SelectFilter::make('follow_up_type')
                    ->label('Type')
                    ->multiple()
                    ->options([
                        'Call' => 'Call',
                        'WhatsApp' => 'WhatsApp',
                        'Email' => 'Email',
                        'Visit' => 'Visit',
                    ]),

                SelectFilter::make('bank_id')
                    ->label('Bank')
                    ->multiple()
                    ->relationship('bank', 'bank_name'),
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
