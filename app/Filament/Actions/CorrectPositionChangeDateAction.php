<?php

namespace App\Filament\Actions;

use App\Models\Employee;
use App\Services\EmployeePositionChangeService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * "Correct Effective Date" on the Employee page: moves the last promotion,
 * demotion or target change (EmployeePositionChangeService::redateLastChange)
 * to the day it really took effect, together with the team moves made with
 * it — for a change keyed in with the wrong date. Every month figure
 * follows the corrected day.
 */
class CorrectPositionChangeDateAction
{
    public static function make(): Action
    {
        return Action::make('correctChangeDate')
            ->label('Correct Effective Date')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->visible(fn (Employee $record): bool => app(EmployeePositionChangeService::class)->lastChange($record) !== null)
            ->modalWidth(Width::Large)
            ->modalIcon(Heroicon::OutlinedCalendarDays)
            ->modalHeading(fn (Employee $record): string => "Correct the effective date of {$record->emp_name}'s last change")
            ->modalDescription(fn (Employee $record): string => self::summary($record))
            ->fillForm(fn (Employee $record): array => [
                'effective_date' => app(EmployeePositionChangeService::class)->lastChange($record)['date']->toDateString(),
            ])
            ->schema([
                DatePicker::make('effective_date')
                    ->label('Correct effective date')
                    ->helperText('Targets, achievement and team figures follow the change from this day; the months before it keep the old position and target.')
                    ->required()
                    ->maxDate(now())
                    ->native(false)
                    ->displayFormat('d F Y'),
            ])
            ->modalSubmitActionLabel('Save date')
            ->action(function (Employee $record, array $data, Action $action, $livewire): void {
                $service = app(EmployeePositionChangeService::class);
                $summary = $service->lastChangeSummary($record);

                try {
                    $service->redateLastChange($record, Carbon::parse($data['effective_date']), Auth::user());
                } catch (ValidationException $exception) {
                    EmployeeDependencyStep::refuse($exception, $action);
                }

                $record->refresh();

                if ($livewire instanceof EditRecord || $livewire instanceof ViewRecord) {
                    $livewire->refreshFormData(['designation', 'category']);
                }

                Notification::make()
                    ->title("{$record->emp_name} updated")
                    ->body(trim($summary.' Now effective from '.Carbon::parse($data['effective_date'])->format('d M Y').'.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Which change is corrected, and from which date.
     */
    private static function summary(Employee $employee): string
    {
        $service = app(EmployeePositionChangeService::class);
        $change = $service->lastChange($employee);

        if ($change === null) {
            return '';
        }

        $teamRows = $change['rows']->where('employee_id', '!=', $employee->id)->pluck('employee_id')->unique()->count();

        return trim($service->lastChangeSummary($employee).' Recorded as effective from '.$change['date']->format('d M Y').'.')
            .($teamRows > 0 ? " The {$teamRows} team ".str('move')->plural($teamRows).' made with it move to the new date too.' : '');
    }
}
