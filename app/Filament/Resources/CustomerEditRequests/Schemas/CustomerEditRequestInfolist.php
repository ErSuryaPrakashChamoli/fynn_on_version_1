<?php

namespace App\Filament\Resources\CustomerEditRequests\Schemas;

use App\Enums\CustomerEditRequestStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CustomerEditRequest;
use App\Models\CustomerEditRequestItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerEditRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('customer.customer_name')
                                ->label('Customer')
                                ->url(fn (CustomerEditRequest $record): ?string => $record->customer
                                    ? CustomerResource::getUrl('view', ['record' => $record->customer])
                                    : null)
                                ->color('primary'),
                            TextEntry::make('section')
                                ->label('Section')
                                ->state(fn (CustomerEditRequest $record): string => $record->sectionLabel()),
                            TextEntry::make('status')
                                ->badge()
                                ->formatStateUsing(fn (CustomerEditRequestStatus $state): string => $state->label())
                                ->color(fn (CustomerEditRequestStatus $state): string => $state->color()),
                            TextEntry::make('requester.name')
                                ->label('Raised by')
                                ->helperText(fn (CustomerEditRequest $record): ?string => $record->created_at?->format('d M Y, h:i A')),
                        ]),

                        TextEntry::make('reason')
                            ->label('Reason')
                            ->columnSpanFull(),
                    ]),

                Section::make('Changes')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('field')
                                    ->label('Field')
                                    ->state(fn (CustomerEditRequestItem $record): string => $record->fieldLabel())
                                    ->weight('bold'),
                                TextEntry::make('current_value')
                                    ->label('Current value (when raised)')
                                    ->state(fn (CustomerEditRequestItem $record): string => $record->display($record->current_value)),
                                TextEntry::make('requested_value')
                                    ->label('Requested value')
                                    ->state(fn (CustomerEditRequestItem $record): string => $record->display($record->requested_value))
                                    ->color('success'),
                                TextEntry::make('overwritten_value')
                                    ->label('Value overwritten')
                                    ->state(fn (CustomerEditRequestItem $record): string => $record->applied_at
                                        ? $record->display($record->overwritten_value)
                                        : 'Not applied')
                                    ->helperText(fn (CustomerEditRequestItem $record): ?string => $record->applied_at
                                        && $record->overwritten_value !== $record->current_value
                                            ? 'The file changed after the request was raised.'
                                            : null),
                            ])
                            ->columns(4),
                    ]),

                Section::make('Review')
                    ->columnSpanFull()
                    ->visible(fn (CustomerEditRequest $record): bool => ! $record->isPending())
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
                            TextEntry::make('reviewed_at')->label('Reviewed at')->dateTime('d M Y, h:i A')->placeholder('—'),
                            TextEntry::make('applied_at')->label('Applied to the file at')->dateTime('d M Y, h:i A')->placeholder('Not applied'),
                        ]),
                        TextEntry::make('review_note')->label('Admin note')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
