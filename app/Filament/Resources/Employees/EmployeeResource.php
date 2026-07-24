<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Resources\Employees\Schemas\EmployeeInfolist;
use App\Filament\Resources\Employees\Tables\EmployeesTable;
use App\Models\Employee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;


use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;


use Filament\Forms\Components\FileUpload;


use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;

use Filament\Forms\Components\DatePicker;

use Filament\Actions\ImportAction;
use App\Filament\Imports\CustomerImporter;
use App\Filament\Imports\EmployeeImporter;



use Filament\Schemas\Components\Utilities\Set;


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

                    // TextInput::make('designation')
                    //     ->required(),

                        // Select::make('designation')
                        // ->label('Position')
                        // ->options([
                        //     '1' => 'Admin',
                        //     '7' => 'Caller',
                        //     '3' => 'Team Leader',
                        //     '2' => 'Manager',
                        //     '5' => 'Cluster Manager',
                        // ])
                        // ->required()
                        // ->searchable()
                        // ->live()
                        // ->afterStateUpdated(function (Set $set, $state) {
                        //     if ($state !== '1') {
                        //         $set('superviser_id', null);
                        //     }
                        //     if (in_array($state, ['3', '4'])) {
                        //         $set('manager_id', null);
                        //     }
                        // })

                        Select::make('designation')
                        ->label('Position')
                        ->options(Employee::designationOptions())
                        ->required()
                        ->searchable()
                        ->live()
                        ->native(false)
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
                        ->relationship('superviser', 'emp_name')
                        ->searchable()
                        ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->emp_name} - ({$record->emp_id})")
                        ->visible(fn (Get $get) => in_array($get('designation'), ['7']))
                        ->required(fn (Get $get) => $get('designation') === '7')
                        ->preload(),

                    Select::make('manager_id')
                        ->label('Manager')
                        ->relationship('manager', 'emp_name')
                        ->searchable()
                         ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->emp_name} - ({$record->emp_id})")
                         ->visible(fn (Get $get) => in_array($get('designation'), ['7', '3']))
                         ->required(fn (Get $get) => in_array($get('designation'), ['3', '7']))
                        ->preload(),

                    Select::make('cluster_id')
                    ->label('Cluster Manager')
                    ->relationship('clusterManager', 'emp_name')
                    ->searchable()
                        ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->emp_name} - ({$record->emp_id})")
                        ->visible(fn (Get $get) => in_array($get('designation'), ['7', '2' , '3']))
                        ->required(fn (Get $get) => in_array($get('designation'), ['3', '2','7']))
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
                        'bhupendra_singh'   => 'Bhupendra Singh',
                        'chanchal_chaudhary' => 'Chanchal Chaudhary',
                        'deepak_singh'      => 'Deepak Singh',
                        'kanak_kumar'       => 'Kanak Kumar',
                        'manoj_sajwan'      => 'Manoj Sajwan',
                        'nitin_thakur'      => 'Nitin Thakur',
                        'prabhat_tyagi'     => 'Prabhat Tyagi',
                        'rohit_sharma'      => 'Rohit Sharma',
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

                    Tables\Columns\TextColumn::make('designation'),

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
                ->paginated([5,10, 25, 50, 100, 'all'])
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
                ->importer(EmployeeImporter::class)
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
