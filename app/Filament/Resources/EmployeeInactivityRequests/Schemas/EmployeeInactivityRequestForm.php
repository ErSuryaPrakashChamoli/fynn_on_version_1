<?php

namespace App\Filament\Resources\EmployeeInactivityRequests\Schemas;

use App\Enums\InactivityRequestStatus;
use App\Models\Employee;
use App\Models\EmployeeInactivityRequest;
use App\Models\User;
use App\Services\MonthlyTargetGate;
use App\Support\EmployeeOptions;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class EmployeeInactivityRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Inactivity ticket')
                    ->description('Raise this instead of inventing a monthly target for somebody who has gone inactive. Their target is skipped for the month straight away; the Admin reviews the ticket afterwards.')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Employee')
                            ->options(fn (): array => self::employeeOptions())
                            ->preload()
                            ->required()
                            // One open ticket per person per month is all
                            // the gate needs; a second would just be noise.
                            ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                                $month = Carbon::parse($get('month') ?: today())->startOfMonth();

                                $exists = EmployeeInactivityRequest::query()
                                    ->where('employee_id', $value)
                                    ->forMonth($month)
                                    ->skipping()
                                    ->exists();

                                if ($exists) {
                                    $fail('There is already an open ticket for this employee for '.$month->format('M Y').'.');
                                }
                            }),

                        DatePicker::make('month')
                            ->label('Month')
                            ->native(false)
                            ->displayFormat('M Y')
                            ->default(today()->startOfMonth())
                            ->required()
                            ->helperText('The month whose commitment target is skipped.')
                            ->dehydrateStateUsing(fn ($state): ?string => $state
                                ? Carbon::parse($state)->startOfMonth()->toDateString()
                                : null),

                        Textarea::make('reason')
                            ->label('Why are they inactive?')
                            ->rows(3)
                            ->required()
                            ->minLength(5)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Only people this user is allowed to set a target for — raising a
     * ticket skips that target, so it is the same authority.
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

    /**
     * Values the create page always fills in itself — a ticket is always
     * pending and always attributed to whoever raised it.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'status' => InactivityRequestStatus::Pending->value,
            'requested_by' => Filament::auth()->id(),
        ];
    }
}
