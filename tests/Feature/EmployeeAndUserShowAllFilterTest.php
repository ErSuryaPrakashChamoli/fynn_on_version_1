<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Proves the Employees list and Users list each carry a toggle filter
 * (defaulting ON, preserving prior behavior) that can be switched OFF to
 * see every record at once, independent of the global month selector —
 * previously the month scope was baked unconditionally into
 * EmployeeResource::getEloquentQuery() / UsersTable's modifyQueryUsing(),
 * with no way to see records outside the selected month from the list.
 */
class EmployeeAndUserShowAllFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'IT']);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(['Admin', 'IT']);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_employees_list_hides_future_joiners_by_default_and_shows_them_with_the_filter_off(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 27));

        $this->actingAsAdmin();

        $currentEmployee = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'doj' => Carbon::create(2026, 8, 1),
            'exit_status' => 'no',
        ]);

        // Joined next month -> outside the selected (August) month window.
        $futureJoiner = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'doj' => Carbon::create(2026, 9, 5),
            'exit_status' => 'no',
        ]);

        Livewire::test(ListEmployees::class)
            ->assertCanSeeTableRecords([$currentEmployee])
            ->assertCanNotSeeTableRecords([$futureJoiner])
            ->filterTable('active_in_selected_month', false)
            ->assertCanSeeTableRecords([$currentEmployee, $futureJoiner]);

        Carbon::setTestNow();
    }

    public function test_users_list_hides_users_created_after_the_selected_month_by_default_and_shows_them_with_the_filter_off(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 27));

        $this->actingAsAdmin();

        $currentUser = User::factory()->create(['created_at' => Carbon::create(2026, 8, 10)]);

        // Created next month -> outside the selected (August) month window.
        $futureUser = User::factory()->create(['created_at' => Carbon::create(2026, 9, 5)]);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$currentUser])
            ->assertCanNotSeeTableRecords([$futureUser])
            ->filterTable('created_in_selected_month', false)
            ->assertCanSeeTableRecords([$currentUser, $futureUser]);

        Carbon::setTestNow();
    }
}
