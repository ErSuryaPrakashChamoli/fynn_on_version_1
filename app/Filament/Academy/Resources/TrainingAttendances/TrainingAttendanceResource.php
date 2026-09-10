<?php

namespace App\Filament\Academy\Resources\TrainingAttendances;

use App\Filament\Academy\Resources\Concerns\TrainerResource;
use App\Filament\Academy\Resources\TrainingAttendances\Pages\CreateTrainingAttendance;
use App\Filament\Academy\Resources\TrainingAttendances\Pages\EditTrainingAttendance;
use App\Filament\Academy\Resources\TrainingAttendances\Pages\ListTrainingAttendances;
use App\Filament\Academy\Resources\TrainingAttendances\Schemas\TrainingAttendanceForm;
use App\Filament\Academy\Resources\TrainingAttendances\Tables\TrainingAttendancesTable;
use App\Models\Training\TrainingAttendance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TrainingAttendanceResource extends Resource
{
    use TrainerResource;

    protected static ?string $model = TrainingAttendance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Delivery';

    protected static ?string $navigationLabel = 'Attendance';

    protected static ?string $slug = 'attendance';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return TrainingAttendanceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrainingAttendancesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingAttendances::route('/'),
            'create' => CreateTrainingAttendance::route('/create'),
            'edit' => EditTrainingAttendance::route('/{record}/edit'),
        ];
    }

    protected static function scopeQueryToTenant(Builder $query): Builder
    {
        return $query->whereHas(
            'batch',
            fn (Builder $batch) => $batch->where('tenant_id', static::currentTenantId())
        );
    }
}
