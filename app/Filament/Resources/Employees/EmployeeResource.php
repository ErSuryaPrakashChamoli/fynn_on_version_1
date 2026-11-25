<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Actions\ChangeEmployeePositionAction;
use App\Filament\Actions\DataDownloadApproval;
use App\Filament\Actions\InactivateEmployeeAction;
use App\Filament\Actions\MoveTeamAction;
use App\Filament\Actions\TransferEmployeeAction;
use App\Filament\Imports\EmployeeImporter;
use App\Filament\Imports\EmployeeUpdateImporter;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Resources\Employees\Schemas\EmployeeInfolist;
use App\Filament\Resources\Employees\Tables\EmployeesTable;
use App\Models\CostCenter;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Position;
use App\Models\TargetCategory;
use App\Models\Unit;
use App\Services\EmployeeDependencyService;
use App\Services\EmployeePositionChangeService;
use App\Services\ReportingLineService;
use App\Support\EmployeeOptions;
use App\Support\HierarchyHelper;
use App\Support\SelectedMonth;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                ->icon(Heroicon::OutlinedIdentification)
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

                    // Lists maintained under Employee Setup; the current
                    // value stays selectable even if it was removed there.
                    Select::make('position')
                        ->label('Designation')
                        ->options(fn (?Employee $record): array => Designation::optionsIncluding($record?->position))
                        ->required()
                        ->native(false),

                    // For someone on the rolls a promotion or demotion goes
                    // through "Change Position / Target", which hands over
                    // their work first and dates the change.
                    Select::make('designation')
                        ->label('Position')
                        ->options(fn (?Employee $record): array => Position::optionsIncluding($record?->designation))
                        ->required()
                        ->searchable()
                        ->disabled(fn (?Employee $record): bool => self::isOnTheRolls($record))
                        ->dehydrated(fn (?Employee $record): bool => ! self::isOnTheRolls($record))
                        // The effective date of the position held: every
                        // target and team figure follows it.
                        ->helperText(fn (?Employee $record): ?string => collect([
                            $record?->exists ? app(EmployeePositionChangeService::class)->positionSince($record) : null,
                            self::isOnTheRolls($record)
                                ? 'Use “Change Position / Target” at the top of this page — it dates the promotion or demotion, and targets and team figures follow that date.'
                                : null,
                        ])->filter()->implode(' ') ?: null)
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
                        // Only a caller carries a category (user decision
                        // 2026-11-24): every leader's target is built from
                        // their team, and Admin / Other Bank Support carry
                        // no LMS target at all. Offered: categories with a
                        // real monthly amount (TargetCategory::callerOptionsIncluding).
                        ->options(fn (?Employee $record): array => TargetCategory::callerOptionsIncluding($record?->category))
                        ->visible(fn (Get $get): bool => self::designationOf($get('designation')) === Employee::DESIGNATION_CALLER)
                        ->required(fn (?Employee $record): bool => ! self::isOnTheRolls($record))
                        // A new target is dated through "Change Position /
                        // Target", so earlier months keep the old one.
                        ->disabled(fn (?Employee $record): bool => self::isOnTheRolls($record))
                        ->dehydrated(fn (?Employee $record): bool => ! self::isOnTheRolls($record))
                        ->helperText(fn (?Employee $record): ?string => collect([
                            $record?->exists ? app(EmployeePositionChangeService::class)->categorySince($record) : null,
                            self::isOnTheRolls($record)
                                ? 'Use “Change Position / Target” at the top of this page — the new target applies from its effective date and earlier months keep the old one.'
                                : null,
                        ])->filter()->implode(' ') ?: null)
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

                                return;
                            }

                            $problem = $record ? self::moveProblem($record, self::designationOf($get('designation')), filled($value) ? (int) $value : null) : null;

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

                    Select::make('cost_center')
                        ->label('Cost Center')
                        ->options(fn (?Employee $record): array => CostCenter::optionsIncluding($record?->cost_center))
                        ->required()
                        ->native(false),

                    Select::make('unit_name')
                        ->label('Unit')
                        ->options(fn (?Employee $record): array => Unit::optionsIncluding($record?->unit_name))
                        ->required()
                        ->native(false),

                    // Someone on the rolls is taken off them only through
                    // the page's "Mark Inactive" action, which clears what
                    // depends on them first; here an exited employee can be
                    // brought back or have their exit date corrected.
                    Select::make('exit_status')
                        ->label('Active Status')
                        ->options([
                            'yes' => 'Inactive',
                            'no' => 'Active',
                        ])
                        ->default('no')
                        ->required()
                        ->disabled(fn (?Employee $record): bool => $record !== null && ! self::hasLeft($record))
                        ->dehydrated(fn (?Employee $record): bool => $record === null || self::hasLeft($record))
                        ->helperText(fn (?Employee $record): ?string => $record !== null && ! self::hasLeft($record)
                            ? 'Use “Mark Inactive” at the top of this page — it clears anything still assigned to them and records the effective date.'
                            : null)
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
     * Whether $record is an existing employee still on the rolls — whose
     * position and target category change only through "Change Position /
     * Target".
     */
    public static function isOnTheRolls(?Employee $record): bool
    {
        return $record !== null && $record->exists && ! self::hasLeft($record);
    }

    /**
     * Whether the employee is off the rolls (employees.exit_status).
     */
    public static function hasLeft(Employee $employee): bool
    {
        return strtolower(trim((string) $employee->exit_status)) === 'yes';
    }

    /**
     * Why the form cannot move $employee to $bossId, or null when it can.
     *
     * A straight move (same position, different boss) is a transfer, and
     * this form cannot say what happens to their team: anybody with a team
     * is sent to the "Transfer Employee" action
     * (EmployeeDependencyService::SCOPE_MOVE); all their own work goes with
     * them. The
     * Position field is locked for anyone on the rolls ("Change Position /
     * Target" promotes and demotes), so a new position here is only ever a
     * correction on an exited employee.
     */
    private static function moveProblem(Employee $employee, ?int $designation, ?int $bossId): ?string
    {
        if (
            self::hasLeft($employee)
            || $designation !== (int) $employee->designation
            || $bossId === HierarchyHelper::directBossId($employee)
        ) {
            return null;
        }

        $dependencies = app(EmployeeDependencyService::class)->moveDependencyCounts($employee);

        return $dependencies === []
            ? null
            : "{$employee->emp_name} still has ".app(EmployeeDependencyService::class)->describe($dependencies)
                .'. Use “Transfer Employee” at the top of this page — '.$employee->emp_name.' can take the whole team, part of it or nobody along, and whoever stays behind gets a new boss in the same step.';
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
                    ->formatStateUsing(fn (?string $state): ?string => TargetCategory::labelFor($state))
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
                    ->formatStateUsing(fn (?string $state): ?string => CostCenter::labelFor($state))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('unit_name')
                    ->label('Unit')
                    ->formatStateUsing(fn (?string $state): ?string => Unit::labelFor($state))
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
                // The day-to-day moves, without opening the employee first.
                ActionGroup::make([
                    TransferEmployeeAction::make(),
                    MoveTeamAction::make(),
                    ChangeEmployeePositionAction::make(),
                    InactivateEmployeeAction::make(),
                ])
                    ->label('Move / Promote')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->dropdownPlacement('bottom-start')
                    ->visible(fn (): bool => auth()->user()?->hasRole('Admin') === true),
            ])
            ->headerActions([
                ActionGroup::make([
                    ImportAction::make('importNewEmployees')
                        ->label('Upload New Employees')
                        ->icon(Heroicon::OutlinedUserPlus)
                        ->modalHeading('Upload New Employees')
                        ->importer(EmployeeImporter::class)
                        ->options(['mode' => EmployeeImporter::MODE_CREATE]),

                    ImportAction::make('updateExistingEmployees')
                        ->label('Update Existing Employees')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->modalHeading('Update Existing Employees')
                        ->modalDescription(null)
                        ->modalContent(fn (ImportAction $action): View => view('filament.components.employee-update-import-steps', [
                            'downloadAction' => $action->getModalAction('downloadExistingEmployees'),
                        ]))
                        ->registerModalActions([
                            DataDownloadApproval::adminOnly(
                                Action::make('downloadExistingEmployees')
                                    ->label('Download existing data')
                                    ->icon(Heroicon::OutlinedArrowDownTray)
                                    ->color('gray')
                                    ->size(Size::Small)
                                    ->action(fn (): StreamedResponse => response()->streamDownload(
                                        function (): void {
                                            echo EmployeeImporter::existingEmployeesCsv();
                                        },
                                        'employees-'.now()->format('Y-m-d').'.csv',
                                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                                    )),
                            ),
                        ])
                        ->importer(EmployeeUpdateImporter::class)
                        ->visible(fn (): bool => auth()->user()?->hasRole('Admin') === true),
                ])
                    ->label('Import Employees')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->button()
                    ->dropdownPlacement('bottom-end'),
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
