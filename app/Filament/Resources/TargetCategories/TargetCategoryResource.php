<?php

namespace App\Filament\Resources\TargetCategories;

use App\Filament\Actions\DeleteWithDependenciesAction;
use App\Filament\Resources\TargetCategories\Pages\ManageTargetCategories;
use App\Models\TargetCategory;
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
 * The "Target Category" choices on the Employee form (employees.category)
 * and the monthly target each one carries. Changing an amount changes the
 * target of every employee in that category.
 */
class TargetCategoryResource extends Resource
{
    protected static ?string $model = TargetCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Employee Setup';

    protected static ?string $navigationLabel = 'Target Categories';

    protected static ?string $modelLabel = 'target category';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),

                TextInput::make('target_amount')
                    ->label('Monthly Target')
                    ->helperText('Leave empty for a category with no own target (the standard ₹25,00,000 applies).')
                    ->indianAmount(min: 1),

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

                TextColumn::make('target_amount')
                    ->label('Monthly Target')
                    ->formatStateUsing(fn (?int $state): ?string => filled($state) ? '₹'.indianNumberFormat($state) : null)
                    ->placeholder('—')
                    ->sortable(),

                ToggleColumn::make('is_active')
                    ->label('Active'),

                TextColumn::make('employees_count')
                    ->label('Employees')
                    ->state(fn (TargetCategory $record): int => $record->employeesCount()),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteWithDependenciesAction::forEmployeeOption(
                    optionLabel: 'target category',
                    fieldLabel: 'Target Category',
                    canDelete: fn (TargetCategory $record): bool => static::canDelete($record),
                ),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTargetCategories::route('/'),
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
            && $record instanceof TargetCategory
            && ! $record->isProtected();
    }
}
