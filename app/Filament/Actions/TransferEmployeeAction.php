<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Services\EmployeeDependencyService;
use App\Services\EmployeeTransferService;
use App\Services\ReportingLineService;
use App\Support\HierarchyHelper;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * "Transfer Employee" on the Employee page: moves the employee to a new
 * boss (EmployeeTransferService) with their own work; past months stay with
 * the old team.
 *
 * A caller takes all their work along and goes straight to the new boss
 * and effective date. A Team Leader or anybody above takes along only the
 * customers and leads they created: first the EmployeeDependencyStep
 * (EmployeeDependencyService::SCOPE_MOVE) hands over their team — which
 * keeps its own work — and whatever somebody else created and gave them.
 * Then the same pop-up asks for the new boss and the transfer happens on
 * the spot.
 */
class TransferEmployeeAction
{
    public static function make(): Action
    {
        $scope = EmployeeDependencyService::SCOPE_MOVE;

        $attached = fn (Employee $record): bool => EmployeeDependencyStep::dependencies($record, scope: $scope) !== [];

        return Action::make('transferEmployee')
            ->label('Transfer Employee')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (Employee $record): bool => Employee::designationRank($record->designation) > 0
                && $record->designation !== Employee::DESIGNATION_BUSINESS_HEAD
                && ! EmployeeResource::hasLeft($record))
            ->modalWidth(fn (Employee $record): Width => $attached($record) ? Width::ThreeExtraLarge : Width::TwoExtraLarge)
            ->modalIcon(fn (Employee $record): ?Heroicon => $attached($record) ? Heroicon::OutlinedExclamationTriangle : null)
            ->modalIconColor('warning')
            ->modalHeading(fn (Employee $record): string => $attached($record)
                ? EmployeeDependencyStep::heading($record, $scope)
                : "Transfer {$record->emp_name}")
            ->modalDescription(fn (Employee $record): string => $attached($record)
                ? EmployeeDependencyStep::description(
                    $record,
                    "Customers and leads they created go with them. {$record->emp_name}'s new boss is asked for once nothing else is left.",
                    scope: $scope,
                )
                : self::takesAlongSummary($record))
            ->fillForm(fn (Employee $record, array $arguments): array => [
                'current_reporting' => ($bossId = HierarchyHelper::directBossId($record))
                    ? app(ReportingLineService::class)->lineSummary($bossId)
                    : 'Nobody',
                'handover_date' => today()->toDateString(),
                'effective_date' => $arguments['effective_date'] ?? today()->toDateString(),
            ])
            ->schema(fn (Employee $record): array => $attached($record)
                ? EmployeeDependencyStep::schema($record, scope: $scope)
                : self::transferSchema($record))
            ->modalSubmitActionLabel(fn (Employee $record): string => $attached($record) ? 'Hand over & continue' : 'Transfer')
            ->action(function (Employee $record, array $data, Action $action, $livewire) use ($attached, $scope): void {
                if ($attached($record)) {
                    EmployeeDependencyStep::clear($record, $data, $action, scope: $scope);

                    ReopenAction::for($action, ['effective_date' => Carbon::parse($data['handover_date'] ?? today())->toDateString()]);

                    return;
                }

                try {
                    app(EmployeeTransferService::class)->transfer(
                        $record,
                        (int) $data['reports_to'],
                        Carbon::parse($data['effective_date']),
                        $data['remarks'] ?? null,
                        Auth::user(),
                    );
                } catch (ValidationException $exception) {
                    EmployeeDependencyStep::refuse($exception, $action);
                }

                if ($livewire instanceof EditRecord) {
                    $livewire->data['reports_to'] = HierarchyHelper::directBossId($record);
                }

                Notification::make()
                    ->title('Employee transferred successfully.')
                    ->success()
                    ->send();
            });
    }

    /**
     * What goes with them, shown above the new boss.
     */
    private static function takesAlongSummary(Employee $employee): string
    {
        return (app(EmployeeDependencyService::class)->takesAllWorkAlong($employee)
            ? 'Their open customers, leads and delegations go with them.'
            : 'The customers and leads they created, and their delegations, go with them.')
            .' Earlier months stay with their old team.';
    }

    /**
     * @return list<Component>
     */
    private static function transferSchema(Employee $employee): array
    {
        return [
            TextInput::make('current_reporting')
                ->label('Currently Reports To')
                ->disabled()
                ->dehydrated(false),

            Select::make('reports_to')
                ->label('New Boss')
                ->options(fn (): array => app(ReportingLineService::class)->bossOptions($employee->designation, $employee->id))
                ->helperText('Anyone more senior may be chosen; the levels in between can be skipped.')
                ->required()
                ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail) use ($employee): void {
                    $problem = app(ReportingLineService::class)->bossProblem($employee->designation, (int) $value, $employee->id);

                    if ($problem !== null) {
                        $fail($problem);
                    }
                })
                ->native(false),

            DatePicker::make('effective_date')
                ->maxDate(now())
                ->required(),

            Textarea::make('remarks')
                ->rows(3),
        ];
    }
}
