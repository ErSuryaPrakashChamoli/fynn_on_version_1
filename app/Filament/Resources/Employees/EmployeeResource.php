<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Imports\EmployeeImporter;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Resources\Employees\Schemas\EmployeeInfolist;
use App\Filament\Resources\Employees\Tables\EmployeesTable;
use App\Models\Employee;
use App\Services\ReportingLineService;
use App\Support\EmployeeOptions;
use App\Support\SelectedMonth;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ImportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    // protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([

            Section::make('Employee Details')
                ->schema([

                    TextInput::make('emp_id')
                        ->label('Employee ID')
                        ->required()
                        ->unique(ignoreRecord: true),

                    TextInput::make('emp_name')
                        ->required(),

                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true),

                    TextInput::make('position')
                        ->label('Designation')
                        ->required(),

                    Select::make('designation')
                        ->label('Position')
                        ->options(Employee::designationOptions())
                        ->required()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                            // Drop a boss who is not senior to the new level.
                            $bossId = $get('reports_to');

                            if (filled($bossId) && app(ReportingLineService::class)->bossProblem(self::designationOf($state), (int) $bossId) !== null) {
                                $set('reports_to', null);
                            }
                        })
                        // A demotion must not leave anybody reporting to
                        // somebody who is no longer senior to them.
                        ->rule(fn (?Employee $record): Closure => function (string $attribute, $value, Closure $fail) use ($record): void {
                            $problem = $record
                                ? app(ReportingLineService::class)->designationProblem($record, self::designationOf($value))
                                : null;

                            if ($problem !== null) {
                                $fail($problem);
                            }
                        })
                        ->native(false),

                    Select::make('category')
                        ->label('Target Category')
                        ->options([
                            '2500000' => 'Silver',
                            '3000000' => 'Gold',
                            '3500000' => 'Diamond',
                            'team_leader' => 'Alpha',
                            'manager' => 'Beta',
                            'cluster_manager' => 'Delta',
                        ])
                        // Only in-tree seats carry an LMS target. Admin and
                        // Other Bank Support sit outside the hierarchy
                        // (designationRank 0) and never read this value —
                        // Other Bank Support targets live in their own module.
                        ->required(fn (Get $get): bool => Employee::designationRank(self::designationOf($get('designation'))) > 0)
                        ->helperText(fn (Get $get): ?string => Employee::designationRank(self::designationOf($get('designation'))) > 0
                            ? null
                            : 'Not used for this position — it carries no LMS target.')
                        ->native(false),

                    // The one reporting choice. Every reporting column
                    // (superviser_id, manager_id, cluster_id,
                    // business_head_id) is derived from it on save — see
                    // ReportingLineService.
                    Select::make('reports_to')
                        ->label('Reports To')
                        ->options(fn (Get $get, ?Employee $record): array => app(ReportingLineService::class)
                            ->bossOptions(self::designationOf($get('designation')), $record?->id))
                        ->helperText(fn (Get $get): string => filled($get('reports_to'))
                            ? 'Reporting line: '.app(ReportingLineService::class)->lineSummary((int) $get('reports_to'))
                            : 'Anyone more senior may be chosen; the levels in between can be skipped.')
                        ->visible(fn (Get $get): bool => Employee::designationRank(self::designationOf($get('designation'))) > 0
                            && self::designationOf($get('designation')) !== Employee::DESIGNATION_BUSINESS_HEAD)
                        ->required(fn (Get $get): bool => app(ReportingLineService::class)
                            ->requiresBoss(self::designationOf($get('designation'))))
                        ->rule(fn (Get $get, ?Employee $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                            $problem = app(ReportingLineService::class)->bossProblem(
                                self::designationOf($get('designation')),
                                filled($value) ? (int) $value : null,
                                $record?->id,
                            );

                            if ($problem !== null) {
                                $fail($problem);
                            }
                        })
                        ->live()
                        ->native(false),

                    DatePicker::make('doj')
                        ->displayFormat('d F Y')
                        ->maxDate(now())
                        ->native(false)
                        ->suffixIcon('heroicon-m-calendar')
                        ->label('Date Of Joining'),

                    DatePicker::make('reporting_date')
                        ->displayFormat('d F Y')
                        ->native(false)
                        ->suffixIcon('heroicon-m-calendar')
                        ->maxDate(now()),

                    // TextInput::make('cost_center'),

                    Select::make('cost_center')
                        ->label('Cost Center')
                        ->options([
                            'anuj_singh_thakur' => 'Anuj Singh Thakur',
                            'bhupendra_singh' => 'Bhupendra Singh',
                            'chanchal_chaudhary' => 'Chanchal Chaudhary',
                            'deepak_singh' => 'Deepak Singh',
                            'kanak_kumar' => 'Kanak Kumar',
                            'manoj_sajwan' => 'Manoj Sajwan',
                            'nitin_thakur' => 'Nitin Thakur',
                            'prabhat_tyagi' => 'Prabhat Tyagi',
                            'rohit_sharma' => 'Rohit Sharma',
                        ])
                        ->required()
                        ->native(false),

                    // TextInput::make('unit_name'),

                    Select::make('unit_name')
                        ->label('Unit')
                        ->options([
                            'kanak_kumar' => 'Kanak Kumar',
                            'rohit_sharma' => 'Rohit Sharma',

                        ])
                        ->required()
                        ->native(false),

                    Select::make('exit_status')
                        ->label('Active Status')
                        ->options([
                            'yes' => 'Inactive',
                            'no' => 'Active',
                        ])
                        ->default('no')
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state) {

                            if ($state === 'no') {
                                $set('exit_date', null);
                            }
                        }),

                    DatePicker::make('exit_date')
                        ->label('Exit Date')
                        ->native(false)
                        ->displayFormat('d F Y')
                        ->suffixIcon('heroicon-m-calendar')
                        ->maxDate(now())
                        ->visible(fn (Get $get) => $get('exit_status') === 'yes')
                        ->required(fn (Get $get) => $get('exit_status') === 'yes'),

                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
        // return EmployeeForm::configure($schema);
    }

    /**
     * The form's designation state as an int, or null when none is chosen.
     */
    private static function designationOf(mixed $state): ?int
    {
        return filled($state) ? (int) $state : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmployeeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {

        return $table
            ->defaultSort('id', 'desc')
            ->columns([

                Tables\Columns\TextColumn::make('emp_id')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('emp_name')
                    ->searchable()
                    ->sortable(),

                // Tables\Columns\TextColumn::make('designation'),
                Tables\Columns\TextColumn::make('designation')
                    ->label('Designation')
                    ->formatStateUsing(fn ($state) => Employee::designationOptions()[$state] ?? '-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Target Category')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('superviser.emp_name')
                    ->label('Superviser')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('manager.emp_name')
                    ->label('Manager')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cluster.emp_name')
                    ->label('Cluster Manager')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('businessHead.emp_name')
                    ->label('Business Head')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('cost_center')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('unit_name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('doj')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('reporting_date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('exit_status')
                    ->label('Exited')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25, 50, 100, 'all'])
            ->deferFilters(false)
            ->filters([
                Filter::make('active_in_selected_month')
                    ->label('Only employees active this month')
                    ->toggle()
                    ->default(true)
                    ->query(function (Builder $query) {
                        [$start, $end] = SelectedMonth::range();

                        return $query->activeDuring($start, $end);
                    }),

                SelectFilter::make('designation')
                    ->label('Designation')
                    ->multiple()
                    ->options(fn (): array => Employee::designationOptions()),

                SelectFilter::make('manager_id')
                    ->label('Manager')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::forDesignation(Employee::DESIGNATION_MANAGER)),

                SelectFilter::make('superviser_id')
                    ->label('Team Leader')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::forDesignation(Employee::DESIGNATION_TEAM_LEADER)),

                SelectFilter::make('cluster_id')
                    ->label('Cluster Manager')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::forDesignation(Employee::DESIGNATION_CLUSTER)),

                SelectFilter::make('business_head_id')
                    ->label('Business Head')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::forDesignation(Employee::DESIGNATION_BUSINESS_HEAD)),

                SelectFilter::make('exit_status')
                    ->label('Exit Status')
                    ->options([
                        'no' => 'Active',
                        'yes' => 'Exited',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                ViewAction::make(),
            ])
            ->headerActions([
                ImportAction::make()
                    ->label('Import Employees')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->importer(EmployeeImporter::class),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);

        // return EmployeesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'view' => ViewEmployee::route('/{record}'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->check() && auth()->user()->hasRole('Admin');
    }
}
