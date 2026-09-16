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
                            ->label('Minimum count achievement (₹)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->live(onBlur: true)
                            ->helperText(fn ($state): ?string => filled($state)
                                ? indianAmount($state).' — '.indianAmountInWords($state)
                                : null)
                            ->rule(fn (Get $get, ?OtherBankIncentiveSlab $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                                $month = Carbon::parse($get('effective_month') ?: today())->startOfMonth();

                                $exists = OtherBankIncentiveSlab::query()
                                    ->forMonth($month)
                                    ->where('min_achievement', $value)
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
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(fn (Get $get): ?int => $get('payout_type') === OtherBankIncentiveSlab::PAYOUT_PERCENTAGE ? 100 : null)
                            ->required(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
