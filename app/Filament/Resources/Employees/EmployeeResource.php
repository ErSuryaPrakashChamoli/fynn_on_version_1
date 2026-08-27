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
use App\Support\SelectedMonth;
use BackedEnum;
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
                        ->afterStateUpdated(function (Set $set, $state) {
                            if ($state != Employee::DESIGNATION_CALLER) {
                                $set('superviser_id', null);
                            }

                            if (! in_array($state, [
                                Employee::DESIGNATION_TEAM_LEADER,
                                Employee::DESIGNATION_CALLER,
                            ])) {
                                $set('manager_id', null);
                            }

                            if (! in_array($state, [
                                Employee::DESIGNATION_MANAGER,
                                Employee::DESIGNATION_TEAM_LEADER,
                                Employee::DESIGNATION_CALLER,
                            ])) {
                                $set('cluster_id', null);
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
                        ->required()
                        ->native(false),

                    Select::make('superviser_id')
                        ->label('Superviser')
                        ->relationship(
                            'superviser',
                            'emp_name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->where('designation', Employee::DESIGNATION_TEAM_LEADER)
                                ->where('exit_status', '!=', 'yes')
                        )
                        ->searchable()
                        ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->emp_name} - ({$record->emp_id})")
                        ->visible(fn (Get $get) => $get('designation') === Employee::DESIGNATION_CALLER)
                        ->required(fn (Get $get) => $get('designation') === Employee::DESIGNATION_CALLER)
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state): void {
                            if (! $state) {
                                $set('manager_id', null);
                                $set('cluster_id', null);

                                return;
                            }

                            $supervisor = Employee::query()
                                ->with('manager')
                                ->whereKey($state)
                                ->first();

                            $set('manager_id', $supervisor?->manager_id);
                            $set('cluster_id', $supervisor?->manager?->cluster_id);
                        })
                        ->preload(),

                    Select::make('manager_id')
                        ->label('Manager')
                        ->relationship(
                            'manager',
                            'emp_name',
                            modifyQueryUsing: function (Builder $query, Get $get): Builder {
                                $query
                                    ->where('designation', Employee::DESIGNATION_MANAGER)
                                    ->where('exit_status', '!=', 'yes');

                                // Caller: only the Manager to whom the selected Team Leader reports.
                                if ($get('designation') === Employee::DESIGNATION_CALLER) {
                                    $superviserId = $get('superviser_id');

                                    if (! $superviserId) {
                                        return $query->whereRaw('1 = 0');
                                    }

                                    $managerId = Employee::query()
                                        ->whereKey($superviserId)
                                        ->value('manager_id');

                                    return $managerId
                                        ? $query->whereKey($managerId)
                                        : $query->whereRaw('1 = 0');
                                }

                                // Team Leader: Manager is selected directly.
                                return $query;
                            }
                        )
                        ->searchable()
                        ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->emp_name} - ({$record->emp_id})")
                        ->visible(fn (Get $get) => in_array($get('designation'), [
                            Employee::DESIGNATION_CALLER,
                            Employee::DESIGNATION_TEAM_LEADER,
                        ]))
                        ->required(fn (Get $get) => in_array($get('designation'), [
                            Employee::DESIGNATION_CALLER,
                            Employee::DESIGNATION_TEAM_LEADER,
                        ]))
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state): void {
                            if (! $state) {
                                $set('cluster_id', null);

                                return;
                            }

                            $set('cluster_id', Employee::query()
                                ->whereKey($state)
                                ->value('cluster_id'));
                        })
                        ->disabled(fn (Get $get) => $get('designation') === Employee::DESIGNATION_CALLER && ! $get('superviser_id')
                        )
                        ->preload(),

                    Select::make('cluster_id')
                        ->label('Cluster Manager')
                        ->relationship(
                            'clusterManager',
                            'emp_name',
                            modifyQueryUsing: function (Builder $query, Get $get): Builder {
                                $query
                                    ->where('designation', Employee::DESIGNATION_CLUSTER)
                                    ->where('exit_status', '!=', 'yes');

                                // Caller / Team Leader: only the Cluster Manager of the selected Manager.
                                if (in_array($get('designation'), [
                                    Employee::DESIGNATION_CALLER,
                                    Employee::DESIGNATION_TEAM_LEADER,
                                ])) {
                                    $managerId = $get('manager_id');

                                    if (! $managerId) {
                                        return $query->whereRaw('1 = 0');
                                    }

                                    $clusterId = Employee::query()
                                        ->whereKey($managerId)
                                        ->value('cluster_id');

                                    return $clusterId
                                        ? $query->whereKey($clusterId)
                                        : $query->whereRaw('1 = 0');
                                }

                                // Manager: Cluster Manager is selected directly.
                                return $query;
                            }
                        )
                        ->searchable()
                        ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->emp_name} - ({$record->emp_id})")
                        ->visible(fn (Get $get) => in_array($get('designation'), [
                            Employee::DESIGNATION_CALLER,
                            Employee::DESIGNATION_MANAGER,
                            Employee::DESIGNATION_TEAM_LEADER,
                        ]))
                        ->required(fn (Get $get) => in_array($get('designation'), [
                            Employee::DESIGNATION_CALLER,
                            Employee::DESIGNATION_MANAGER,
                            Employee::DESIGNATION_TEAM_LEADER,
                        ]))
                        ->disabled(fn (Get $get) => in_array($get('designation'), [
                            Employee::DESIGNATION_CALLER,
                            Employee::DESIGNATION_TEAM_LEADER,
                        ]) && ! $get('manager_id'))
                        ->preload(),

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
                    ->searchable(),

                // Tables\Columns\TextColumn::make('designation'),
                Tables\Columns\TextColumn::make('designation')
                    ->label('Designation')
                    ->formatStateUsing(fn ($state) => Employee::designationOptions()[$state] ?? '-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('email'),

                Tables\Columns\TextColumn::make('category')
                    ->label('Target Category'),

                Tables\Columns\TextColumn::make('superviser.emp_name')
                    ->label('Superviser'),

                Tables\Columns\TextColumn::make('manager.emp_name')
                    ->label('Manager'),

                Tables\Columns\TextColumn::make('cost_center'),

                Tables\Columns\TextColumn::make('unit_name'),

                Tables\Columns\TextColumn::make('doj')
                    ->date('d M Y'),

                Tables\Columns\TextColumn::make('reporting_date')
                    ->date('d M Y'),

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
