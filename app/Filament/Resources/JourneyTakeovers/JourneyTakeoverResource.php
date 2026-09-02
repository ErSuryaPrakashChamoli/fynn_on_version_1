<?php

namespace App\Filament\Resources\JourneyTakeovers;

use App\Filament\Resources\JourneyTakeovers\Pages\ListJourneyTakeovers;
use App\Filament\Resources\JourneyTakeovers\Tables\JourneyTakeoversTable;
use App\Models\JourneyTakeover;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JourneyTakeoverResource extends Resource
{
    protected static ?string $model = JourneyTakeover::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $navigationLabel = 'Emergency Takeovers';

    protected static ?string $modelLabel = 'Journey Takeover';

    protected static ?string $pluralModelLabel = 'Journey Takeovers';

    public static function table(Table $table): Table
    {
        return JourneyTakeoversTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user->hasRole('Admin')) {
            return $query;
        }

        $employee = $user->employee;

        if (! $employee) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('takeover_by_id', $employee->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJourneyTakeovers::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['Admin', 'Cluster Manager', 'Business Head']);
    }
}
