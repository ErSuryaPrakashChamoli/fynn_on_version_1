<?php

namespace App\Filament\Resources\PerformanceMetricRatios;

use App\Filament\Resources\PerformanceMetricRatios\Pages\CreatePerformanceMetricRatio;
use App\Filament\Resources\PerformanceMetricRatios\Pages\EditPerformanceMetricRatio;
use App\Filament\Resources\PerformanceMetricRatios\Pages\ListPerformanceMetricRatios;
use App\Filament\Resources\PerformanceMetricRatios\Schemas\PerformanceMetricRatioForm;
use App\Filament\Resources\PerformanceMetricRatios\Tables\PerformanceMetricRatiosTable;
use App\Models\PerformanceMetricRatio;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PerformanceMetricRatioResource extends Resource
{
    protected static ?string $model = PerformanceMetricRatio::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Ratio Builder';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PerformanceMetricRatioForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PerformanceMetricRatiosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPerformanceMetricRatios::route('/'),
            'create' => CreatePerformanceMetricRatio::route('/create'),
            'edit' => EditPerformanceMetricRatio::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('Admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('Admin') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->hasRole('Admin') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('Admin') ?? false;
    }
}
