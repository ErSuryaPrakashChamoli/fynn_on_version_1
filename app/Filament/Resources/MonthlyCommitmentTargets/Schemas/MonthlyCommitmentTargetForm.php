<?php

namespace App\Filament\Resources\MonthlyCommitmentTargets\Schemas;

use App\Enums\CommitmentStage;
use App\Models\Employee;
use App\Services\DailyCommitmentService;
use App\Support\EmployeeOptions;
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
                            ->required(),

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
     * Only employees the current user can see — an Admin sees everyone.
     *
     * @return array<int, string>
     */
    protected static function employeeOptions(): array
    {
        $user = Filament::auth()->user();

        return Employee::query()
            ->whereIn('id', app(DailyCommitmentService::class)->visibleEmployeeIds($user))
            ->orderBy('emp_name')
            ->get(['id', 'emp_name', 'emp_id'])
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => EmployeeOptions::label($employee),
            ])
            ->all();
    }
}
