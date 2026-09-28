<?php

namespace App\Filament\Resources\OtherBankIncentiveSlabs\Schemas;

use App\Models\OtherBankIncentiveSlab;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class OtherBankIncentiveSlabForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Incentive slab')
                    ->description('Slabs sharing an effective month form one ladder. A ladder applies from its effective month until a later month defines a new one. The highest slab whose minimum the other-bank count achievement reaches is paid.')
                    ->schema([
                        DatePicker::make('effective_month')
                            ->label('Effective from')
                            ->native(false)
                            ->displayFormat('M Y')
                            ->default(today()->startOfMonth())
                            ->required()
                            ->dehydrateStateUsing(fn ($state): ?string => $state
                                ? Carbon::parse($state)->startOfMonth()->toDateString()
                                : null),

                        TextInput::make('min_achievement')
                            ->label('Minimum count achievement')
                            ->required()
                            ->indianAmount(min: 0)
                            ->rule(fn (Get $get, ?OtherBankIncentiveSlab $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                                $month = Carbon::parse($get('effective_month') ?: today())->startOfMonth();

                                // The field shows Indian grouping ("12,50,000"); compare the amount.
                                $exists = OtherBankIncentiveSlab::query()
                                    ->forMonth($month)
                                    ->where('min_achievement', (float) preg_replace('/[^0-9.]/', '', (string) $value))
                                    ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($exists) {
                                    $fail('A slab with this minimum already exists for '.$month->format('M Y').'.');
                                }
                            }),

                        Select::make('payout_type')
                            ->label('Payout type')
                            ->options(OtherBankIncentiveSlab::payoutTypeOptions())
                            ->default(OtherBankIncentiveSlab::PAYOUT_FIXED)
                            ->required()
                            ->live(),

                        TextInput::make('payout_value')
                            ->label(fn (Get $get): string => $get('payout_type') === OtherBankIncentiveSlab::PAYOUT_PERCENTAGE
                                ? 'Payout (% of achievement)'
                                : 'Payout amount (₹)')
                            ->required()
                            // "₹" for a fixed payout, "%" for a percentage.
                            ->prefix(fn (Get $get): ?string => $get('payout_type') === OtherBankIncentiveSlab::PAYOUT_PERCENTAGE ? null : '₹')
                            ->suffix(fn (Get $get): ?string => $get('payout_type') === OtherBankIncentiveSlab::PAYOUT_PERCENTAGE ? '%' : null)
                            ->indianAmount(
                                words: false,
                                min: 0,
                                max: fn (Get $get): ?int => $get('payout_type') === OtherBankIncentiveSlab::PAYOUT_PERCENTAGE ? 100 : null,
                            )
                            ->amountInWords(fn (Get $get): bool => $get('payout_type') !== OtherBankIncentiveSlab::PAYOUT_PERCENTAGE),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
