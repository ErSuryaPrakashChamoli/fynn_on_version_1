<?php

namespace App\Filament\Resources\MonthlyCommitmentTargets\Schemas;

use App\Enums\CommitmentStage;
use App\Models\Employee;
use App\Models\MonthlyCommitmentTarget;
use App\Models\User;
use App\Services\MonthlyTargetGate;
use App\Support\EmployeeOptions;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class MonthlyCommitmentTargetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Monthly target')
                    ->description('Used only by the Daily Commitment module. The existing LMS target and incentive calculation are not affected.')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Employee')
                            ->options(fn (): array => self::employeeOptions())
                            ->preload()
                            ->required()
                            // One row per employee per month is a database
                            // constraint; catch it here so a duplicate reads
                            // as a form error rather than a 500.
                            ->rule(fn (Get $get, ?MonthlyCommitmentTarget $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                                $month = Carbon::parse($get('month') ?: today())->startOfMonth();

                                $exists = MonthlyCommitmentTarget::query()
                                    ->where('employee_id', $value)
                                    ->forMonth($month)
                                    ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($exists) {
                                    $fail('This employee already has a target for '.$month->format('M Y').'.');
                                }
                            }),

                        DatePicker::make('month')
                            ->label('Month')
                            ->native(false)
                            ->displayFormat('M Y')
                            ->default(today()->startOfMonth())
                            ->required()
                            // Stored as the first of the month so a month is
                            // one row, whatever day the admin happens to pick.
                            ->dehydrateStateUsing(fn ($state): ?string => $state
                                ? Carbon::parse($state)->startOfMonth()->toDateString()
                                : null),

                        Select::make('stage')
                            ->label('Stage')
                            ->options(CommitmentStage::commitableOptions())
                            ->native(false)
                            ->required()
                            ->live(),

                        TextInput::make('target_amount')
                            ->label('Target amount (₹)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(fn (Get $get): bool => $get('stage') !== CommitmentStage::Otp->value)
                            ->visible(fn (Get $get): bool => $get('stage') !== CommitmentStage::Otp->value),

                        TextInput::make('target_count')
                            ->label('Target OTPs')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(fn (Get $get): bool => $get('stage') === CommitmentStage::Otp->value)
                            ->visible(fn (Get $get): bool => $get('stage') === CommitmentStage::Otp->value),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Only employees whose target this user is allowed to set: a Manager
     * gets their own callers, the Admin line gets the management levels
     * (see MonthlyTargetGate::assignableEmployeeIds()).
     *
     * @return array<int, string>
     */
    protected static function employeeOptions(): array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return Employee::query()
            ->whereIn('id', app(MonthlyTargetGate::class)->assignableEmployeeIds($user))
            ->orderBy('emp_name')
            ->get(['id', 'emp_name', 'emp_id'])
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => EmployeeOptions::label($employee),
            ])
            ->all();
    }
}
