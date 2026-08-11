<?php

namespace App\Filament\Resources\FollowUps;

use App\Filament\Resources\FollowUps\Pages\CreateFollowUp;
use App\Filament\Resources\FollowUps\Pages\EditFollowUp;
use App\Filament\Resources\FollowUps\Pages\ListFollowUps;
use App\Filament\Resources\FollowUps\Schemas\FollowUpForm;
use App\Filament\Resources\FollowUps\Tables\FollowUpsTable;
use App\Models\FollowUp;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FollowUpResource extends Resource
{
    protected static ?string $model = FollowUp::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return FollowUpForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FollowUpsTable::configure($table);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $user = Filament::auth()->user();

        $employeeId = $user?->employee?->id;

        return parent::getEloquentQuery()
            ->where('employee_id', $employeeId);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFollowUps::route('/'),
            'create' => CreateFollowUp::route('/create'),
            'edit' => EditFollowUp::route('/{record}/edit'),
        ];
    }
}
