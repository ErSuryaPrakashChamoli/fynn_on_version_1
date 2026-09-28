<?php

namespace App\Filament\Resources\CustomerEditRequests\Tables;

use App\Enums\CustomerEditRequestStatus;
use App\Filament\Resources\CustomerEditRequests\CustomerEditRequestActions;
use App\Models\CustomerEditRequest;
use App\Support\CustomerEditableFields;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CustomerEditRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer.customer_name')
                    ->label('Customer')
                    ->description(fn (CustomerEditRequest $record): ?string => $record->customer?->application_no ?: $record->customer?->mobile_no)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('section')
                    ->label('Section')
                    ->formatStateUsing(fn (string $state): string => CustomerEditableFields::sectionLabel($state))
                    ->sortable(),

                TextColumn::make('fields')
                    ->label('Fields')
                    ->state(fn (CustomerEditRequest $record): string => $record->fieldsLabel())
                    ->wrap()
                    ->limit(80),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (CustomerEditRequestStatus $state): string => $state->label())
                    ->color(fn (CustomerEditRequestStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Reason')
                    ->wrap()
                    ->limit(80)
                    ->searchable(),

                TextColumn::make('requester.name')
                    ->label('Raised by')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Raised')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),

                TextColumn::make('reviewer.name')
                    ->label('Reviewed by')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                TextColumn::make('reviewed_at')
                    ->label('Reviewed')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(CustomerEditRequestStatus::options()),

                SelectFilter::make('section')
                    ->options(CustomerEditableFields::sectionOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                CustomerEditRequestActions::approve(),
                CustomerEditRequestActions::reject(),
            ]);
    }
}
