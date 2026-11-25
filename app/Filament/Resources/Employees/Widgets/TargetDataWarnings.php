<?php

namespace App\Filament\Resources\Employees\Widgets;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Support\TargetLedger;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * The two data problems that make a month's targets wrong without any
 * error (user decision 2026-11-24), for the Admin to fix on the Employees
 * list:
 *
 * - callers on the rolls with no Target Category carrying a monthly amount,
 *   whose target counts as nothing (TargetLedger::missingCategoryIds());
 * - people whose bosses on record have all left, whose target reaches only
 *   the company total (TargetLedger::brokenLineIds()).
 *
 * Read for the current calendar month; shows nothing when both are empty.
 */
class TargetDataWarnings extends Widget
{
    protected string $view = 'filament.resources.employees.widgets.target-data-warnings';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) Filament::auth()->user()?->hasRole('Admin');
    }

    /**
     * @return array{month: string, missing: list<array{name: string, emp_id: ?string, url: string}>, broken: list<array{name: string, emp_id: ?string, url: string}>}
     */
    protected function getViewData(): array
    {
        $ledger = TargetLedger::forMonth(now());

        return [
            'month' => now()->format('F Y'),
            'missing' => $this->people($ledger->missingCategoryIds()),
            'broken' => $this->people($ledger->brokenLineIds()),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{name: string, emp_id: ?string, url: string}>
     */
    private function people(array $ids): array
    {
        return Employee::query()
            ->whereIn('id', $ids)
            ->orderBy('emp_name')
            ->get(['id', 'emp_name', 'emp_id'])
            ->map(fn (Employee $employee): array => [
                'name' => (string) $employee->emp_name,
                'emp_id' => $employee->emp_id,
                'url' => EmployeeResource::getUrl('edit', ['record' => $employee]),
            ])
            ->all();
    }
}
