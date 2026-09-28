<?php

namespace App\Filament\Resources\Positions;

use App\Filament\Actions\DeleteWithDependenciesAction;
use App\Filament\Resources\Positions\Pages\ManagePositions;
use App\Models\Position;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The levels offered as "Position" on the Employee form (employees.designation).
 * Built-in positions drive the reporting hierarchy and can only be renamed;
 * positions added here sit outside the reporting tree, like Admin.
 */
class PositionResource extends Resource
{
    protected static ?string $model = Position::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Employee Setup';

    protected static ?string $navigationLabel = 'Positions';

    protected static ?string $modelLabel = 'position';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText(fn (?Position $record): string => $record?->is_system
                        ? 'Built-in position: the reporting hierarchy depends on it, so it can be renamed but not deleted.'
                        : 'Positions added here sit outside the reporting hierarchy — no Reports To and no LMS target, like Admin.'),

                Toggle::make('is_active')
                    ->label('Active')
                    ->helperText('An inactive one is no longer offered on the Employee form; employees who already have it keep it.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_system')
                    ->label('Built-in')
                    ->boolean(),

                ToggleColumn::make('is_active')
                    ->label('Active'),

                TextColumn::make('employees_count')
                    ->label('Employees')
                    ->state(fn (Position $record): int => $record->employeesCount()),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteWithDependenciesAction::forEmployeeOption(
                    optionLabel: 'position',
                    fieldLabel: 'Position',
                    canDelete: fn (Position $record): bool => static::canDelete($record),
                ),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePositions::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->hasRole('Admin');
    }

    /**
     * Built-in options stay. One still held by employees can be deleted
     * once they are moved — the delete pop-up offers that.
     */
    public static function canDelete(Model $record): bool
    {
        return static::canAccess()
            && $record instanceof Position
            && ! $record->isProtected();
    }
}
