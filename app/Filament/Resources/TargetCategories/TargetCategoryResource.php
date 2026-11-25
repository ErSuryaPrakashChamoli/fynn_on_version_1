<?php

namespace App\Filament\Resources\TargetCategories;

use App\Filament\Actions\DeleteWithDependenciesAction;
use App\Filament\Resources\TargetCategories\Pages\ManageTargetCategories;
use App\Models\TargetCategory;
use App\Models\TargetCategoryAmount;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * The "Target Category" choices for callers on the Employee form
 * (employees.category) and the monthly target each one carries.
 *
 * Amounts are dated by month (user decision 2026-11-24): changing one asks
 * which month it applies from — never earlier than the current month — and
 * every earlier month keeps the amount it had (TargetCategory::targetAmountFor()).
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
                    ->helperText(fn (?TargetCategory $record): string => collect([
                        'Leave empty for a category that carries no target — a caller can never be given one.',
                        $record ? self::amountHistoryText($record) : null,
                    ])->filter()->implode(' '))
                    ->indianAmount(min: 1),

                // Not a column: TargetCategory::$amountEffectiveFrom, set in
                // the Edit action's using().
                Select::make('effective_from')
                    ->label('New amount applies from')
                    ->options(fn (): array => self::effectiveMonthOptions())
                    ->default(fn (): string => now()->startOfMonth()->toDateString())
                    ->helperText('Earlier months keep the amount they had, so closed months never change.')
                    ->required()
                    ->visibleOn('edit')
                    ->native(false),

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
                    // The amount in force this month; an amount dated for a
                    // later month is shown underneath.
                    ->state(fn (TargetCategory $record): ?float => TargetCategory::targetAmountFor($record->code))
                    ->formatStateUsing(fn (?float $state): ?string => filled($state) ? '₹'.indianNumberFormat($state) : null)
                    ->description(fn (TargetCategory $record): ?string => self::upcomingAmountText($record))
                    ->placeholder('—'),

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
                EditAction::make()
                    ->fillForm(fn (TargetCategory $record): array => [
                        'name' => $record->name,
                        'target_amount' => $record->target_amount,
                        'is_active' => $record->is_active,
                        'effective_from' => now()->startOfMonth()->toDateString(),
                    ])
                    ->using(function (TargetCategory $record, array $data): TargetCategory {
                        $record->amountEffectiveFrom = $data['effective_from'] ?? null;
                        unset($data['effective_from']);

                        $record->update($data);

                        return $record;
                    }),
                DeleteWithDependenciesAction::forEmployeeOption(
                    optionLabel: 'target category',
                    fieldLabel: 'Target Category',
                    canDelete: fn (TargetCategory $record): bool => static::canDelete($record),
                ),
            ]);
    }

    /**
     * The months a changed amount may apply from: this one and the next
     * twelve, "Y-m-d" (the 1st) => "November 2026".
     *
     * @return array<string, string>
     */
    public static function effectiveMonthOptions(): array
    {
        $options = [];

        for ($month = now()->startOfMonth(), $i = 0; $i <= 12; $i++, $month = $month->copy()->addMonthNoOverflow()) {
            $options[$month->toDateString()] = $month->format('F Y');
        }

        return $options;
    }

    /**
     * "Amounts on record: ₹25,00,000 from the start; ₹28,00,000 from Dec 2026."
     */
    private static function amountHistoryText(TargetCategory $record): ?string
    {
        $history = $record->amountHistory();

        if (count($history) <= 1) {
            return null;
        }

        return 'Amounts on record: '.collect($history)
            ->map(fn (array $row): string => ($row['amount'] === null ? 'no target' : '₹'.indianNumberFormat($row['amount']))
                .($row['from'] <= TargetCategoryAmount::FROM_THE_START ? ' from the start' : ' from '.Carbon::parse($row['from'])->format('M Y')))
            ->join('; ').'.';
    }

    /**
     * An amount dated for a later month, e.g. "₹28,00,000 from Dec 2026".
     */
    private static function upcomingAmountText(TargetCategory $record): ?string
    {
        $currentMonth = now()->startOfMonth()->toDateString();

        $upcoming = collect($record->amountHistory())->first(fn (array $row): bool => $row['from'] > $currentMonth);

        return $upcoming === null
            ? null
            : ($upcoming['amount'] === null ? 'No target' : '₹'.indianNumberFormat($upcoming['amount'])).' from '.Carbon::parse($upcoming['from'])->format('M Y');
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
