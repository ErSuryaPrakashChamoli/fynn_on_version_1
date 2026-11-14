<?php

namespace App\Livewire;

use App\Filament\Pages\EmployeeLookup;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeLookupAccess;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The topbar "Find employee" box: type a name or employee ID, pick the
 * person from the dropdown ("Asha Rao · FYN-0142"), and their profile
 * opens on the Employee Lookup page.
 *
 * The Admin finds everyone; everybody else finds themselves and their
 * team down the line (App\Support\EmployeeLookupAccess). The lookup page
 * checks the same rule again, so a hand-typed URL cannot widen it.
 */
class EmployeeQuickSearch extends Component
{
    /**
     * Results shown in the dropdown at once.
     */
    public const RESULT_LIMIT = 10;

    public string $search = '';

    /**
     * @return list<array{id: int, name: string, emp_id: string, designation: string, exited: bool, url: string}>
     */
    #[Computed]
    public function results(): array
    {
        if (mb_strlen(trim($this->search)) < 1) {
            return [];
        }

        $user = Filament::auth()->user();
        $designations = Employee::designationOptions();

        return EmployeeLookupAccess::search($user instanceof User ? $user : null, $this->search, self::RESULT_LIMIT)
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'name' => (string) $employee->emp_name,
                'emp_id' => (string) $employee->emp_id,
                'designation' => $designations[$employee->designation] ?? '',
                'exited' => EmployeeLookupAccess::isExited($employee),
                'url' => EmployeeLookup::getUrl(['employee' => $employee->id]),
            ])
            ->values()
            ->all();
    }

    public function render(): View
    {
        return view('livewire.employee-quick-search');
    }
}
