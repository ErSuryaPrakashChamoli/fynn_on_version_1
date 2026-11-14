<?php

namespace App\Filament\Resources\CostCenters;

use App\Filament\Actions\DeleteWithDependenciesAction;
use App\Filament\Actions\EmployeeOptionActivationActions;
use App\Filament\Resources\CostCenters\Pages\ManageCostCenters;
use App\Models\CostCenter;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The "Cost Center" choices on the Employee form (employees.cost_center).
 * Employees hold the generated code, so renaming is safe. Switched off only
 * through the Inactivate action: its employees are moved first, then the
 * effective date is recorded.
 */
class CostCenterResource extends Resource
{
    protected static ?string $model = CostCenter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Employee Setup';

    protected static ?string $navigationLabel = 'Cost Centers';

    protected static ?string $modelLabel = 'cost center';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
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

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('inactive_from')
                    ->label('Inactive from')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('employees_count')
                    ->label('Employees')
                    ->state(fn (CostCenter $record): int => $record->employeesCount()),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                EmployeeOptionActivationActions::inactivate(
                    optionLabel: 'cost center',
                    fieldLabel: 'Cost Center',
                    canManage: fn (): bool => static::canAccess(),
                ),
                EmployeeOptionActivationActions::activate(
                    optionLabel: 'cost center',
                    canManage: fn (): bool => static::canAccess(),
                ),
                DeleteWithDependenciesAction::forEmployeeOption(
                    optionLabel: 'cost center',
                    fieldLabel: 'Cost Center',
                    canDelete: fn (CostCenter $record): bool => static::canDelete($record),
                ),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCostCenters::route('/'),
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
            && $record instanceof CostCenter
            && ! $record->isProtected();
    }
}
