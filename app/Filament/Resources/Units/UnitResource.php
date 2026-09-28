<?php

namespace App\Filament\Resources\Units;

use App\Filament\Actions\DeleteWithDependenciesAction;
use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The "Unit" choices on the Employee form (employees.unit_name). Employees
 * hold the generated code, so renaming is safe.
 */
class UnitResource extends Resource
{
    protected static ?string $model = Unit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Employee Setup';

    protected static ?string $navigationLabel = 'Units';

    protected static ?string $modelLabel = 'unit';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),

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

                ToggleColumn::make('is_active')
                    ->label('Active'),

                TextColumn::make('employees_count')
                    ->label('Employees')
                    ->state(fn (Unit $record): int => $record->employeesCount()),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteWithDependenciesAction::forEmployeeOption(
                    optionLabel: 'unit',
                    fieldLabel: 'Unit',
                    canDelete: fn (Unit $record): bool => static::canDelete($record),
                ),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUnits::route('/'),
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
            && $record instanceof Unit
            && ! $record->isProtected();
    }
}
