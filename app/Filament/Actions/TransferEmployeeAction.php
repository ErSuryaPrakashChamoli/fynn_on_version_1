<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
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
 * "Transfer Employee" on the Employee page: moves the employee alone to a
 * new boss (EmployeeTransferService).
 *
 * Two steps in one pop-up. While anything is still attached to them —
 * their team, open customers, leads, delegations — it is the
 * EmployeeDependencyStep: listed, and handed over or cancelled right
 * there. Once nothing is left the same pop-up asks for the new boss and the
 * effective date, and the transfer happens on the spot.
 */
class TransferEmployeeAction
{
    public static function make(): Action
    {
        $attached = fn (Employee $record): bool => EmployeeDependencyStep::dependencies($record) !== [];

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
                ? EmployeeDependencyStep::heading($record)
                : "Transfer {$record->emp_name}")
            ->modalDescription(fn (Employee $record): ?string => $attached($record)
                ? EmployeeDependencyStep::description($record, 'The new boss is asked for once nothing is left.')
                : null)
            ->fillForm(fn (Employee $record, array $arguments): array => [
                'current_reporting' => ($bossId = HierarchyHelper::directBossId($record))
                    ? app(ReportingLineService::class)->lineSummary($bossId)
                    : 'Nobody',
                'handover_date' => today()->toDateString(),
                'effective_date' => $arguments['effective_date'] ?? today()->toDateString(),
            ])
            ->schema(fn (Employee $record): array => $attached($record)
                ? EmployeeDependencyStep::schema($record)
                : self::transferSchema($record))
            ->modalSubmitActionLabel(fn (Employee $record): string => $attached($record) ? 'Hand over & continue' : 'Transfer')
            ->action(function (Employee $record, array $data, Action $action, $livewire) use ($attached): void {
                if ($attached($record)) {
                    EmployeeDependencyStep::clear($record, $data, $action);

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
