<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Employee;
use App\Services\HierarchyRoleService;
use App\Services\OtherBankSupportService;
use App\Support\EmployeeOptions;
use App\Support\ReportingTree;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        // One tree for the whole dropdown: ReportingTree::load() is a single
        // query, but labelling each row separately would run one per option.
        // Built per schema instance, so it is never reused across requests.
        $reportingTree = null;
        $resolveReportingTree = function () use (&$reportingTree): ReportingTree {
            return $reportingTree ??= ReportingTree::load();
        };

        return $schema
            ->components([

                Section::make('User Account')
                    ->schema([

                        Select::make('employee_id')
                            ->label('Employee')
                            // Every option reads "Name (EMP ID) - Reports
                            // to: Boss (Designation)". The relationship stays
                            // (employee_id is not fillable on User, so a plain
                            // options array would silently drop it).
                            ->relationship(
                                'employee',
                                'emp_name',
                                fn (Builder $query): Builder => $query->orderBy('emp_name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Employee $record): string => EmployeeOptions::labelWithReportingLine($record, $resolveReportingTree()))
                            ->searchable(['emp_name', 'emp_id'])
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $employee = Employee::find($state);

                                if ($employee) {
                                    $set('name', $employee->emp_name);
                                    $set('email', $employee->email);
                                }
                            })
                            // Other Bank Support sits outside the reporting
                            // tree, so an employee profile is optional there.
                            ->required(fn (Get $get): bool => ! self::isOtherBankSupportRole($get('roles')))
                            ->helperText('Optional for the Other Bank Support role.'),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true),

                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple(false)
                            ->preload()
                            ->searchable()
                            ->required()
                            // Re-evaluates whether Employee is required (optional for Other Bank Support).
                            ->live()
                                // The Business Head role follows the seat — see HierarchyRoleService.
                            ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                                $roleId = is_array($value) ? reset($value) : $value;

                                $problem = app(HierarchyRoleService::class)->roleProblem(
                                    filled($get('employee_id')) ? Employee::query()->find((int) $get('employee_id')) : null,
                                    filled($roleId) ? Role::query()->find((int) $roleId)?->name : null,
                                );

                                if ($problem !== null) {
                                    $fail($problem);
                                }
                            })
                            ->label('Role'),

                        TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->same('password_confirmation'),

                        TextInput::make('password_confirmation')
                            ->label('Confirm Password')
                            ->password()
                            ->revealable()
                            ->dehydrated(false)
                            ->required(fn (string $operation): bool => $operation === 'create'),

                        Toggle::make('is_active')
                            ->label('Account Status')
                            ->helperText('Disable this account to prevent the user from logging in.')
                            ->default(true)
                            ->inline(false)
                            ->onColor('success')
                            ->offColor('danger')
                            ->onIcon('heroicon-m-check-circle')
                            ->offIcon('heroicon-m-x-circle')
                            ->required(),

                    ])
                    ->columns(2)
                    ->columnSpanFull(),

            ]);
    }

    protected static function isOtherBankSupportRole(mixed $roleIds): bool
    {
        $roleIds = array_filter((array) $roleIds);

        return $roleIds !== []
            && Role::query()
                ->whereKey($roleIds)
                ->where('name', OtherBankSupportService::ROLE)
                ->exists();
    }
}
