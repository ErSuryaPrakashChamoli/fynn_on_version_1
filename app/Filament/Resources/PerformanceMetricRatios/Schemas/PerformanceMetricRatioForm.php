<?php

namespace App\Filament\Resources\PerformanceMetricRatios\Schemas;

use App\Models\PerformanceMetricRatio;
use App\Support\Performance\MetricRegistry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PerformanceMetricRatioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ratio Definition')
                    ->description('Pick any two metrics — the ratio is computed live for every employee, on every report, in every period.')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('numerator_key')
                            ->label('Numerator')
                            ->options(MetricRegistry::options())
                            ->required()
                            ->native(false)
                            ->searchable(),

                        Select::make('denominator_key')
                            ->label('Denominator')
                            ->options(MetricRegistry::options())
                            ->required()
                            ->native(false)
                            ->searchable(),

                        Select::make('format')
                            ->options(PerformanceMetricRatio::formatOptions())
                            ->default(PerformanceMetricRatio::FORMAT_PERCENTAGE)
                            ->required()
                            ->native(false),

                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0),

                        Toggle::make('is_active')
                            ->label('Active — show on reports')
                            ->default(true)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }
}
