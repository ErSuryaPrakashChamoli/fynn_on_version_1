<?php

namespace Tests\Feature;

use App\Filament\Resources\CostCenters\Pages\ManageCostCenters;
use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A Unit or Cost Center is switched off only once no employee holds it,
 * and the day it stops being active is recorded (inactive_from) so a
 * figure for an earlier date still treats it as active.
 */
class EmployeeSetupInactivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create()->assignRole(Role::findOrCreate('Admin')));
    }

    public function test_an_unused_unit_is_inactivated_from_the_chosen_effective_date(): void
    {
        $unit = Unit::query()->create(['name' => 'Noida']);
        $effectiveDate = today()->subDays(5);

        Livewire::test(ManageUnits::class)
            ->mountAction(TestAction::make('inactivate')->table($unit))
            ->assertMountedActionModalSee(['Inactivate “Noida”', 'Effective date'])
            ->setActionData(['inactive_from' => $effectiveDate->toDateString()])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('“Noida” inactivated');

        $unit->refresh();

        $this->assertFalse($unit->is_active);
        $this->assertTrue($unit->inactive_from->isSameDay($effectiveDate));
        $this->assertArrayNotHasKey($unit->code, Unit::activeOptions());
    }

    public function test_keeping_the_suggested_effective_date_inactivates_from_today(): void
    {
        $costCenter = CostCenter::query()->create(['name' => 'Old Desk']);

        Livewire::test(ManageCostCenters::class)
            ->callAction(TestAction::make('inactivate')->table($costCenter))
            ->assertHasNoActionErrors();

        $this->assertTrue($costCenter->fresh()->inactive_from->isToday());
    }

    public function test_a_unit_still_held_shows_its_employees_and_asks_for_the_date_only_after_they_are_moved(): void
    {
        $noida = Unit::query()->create(['name' => 'Noida']);
        $gurgaon = Unit::query()->create(['name' => 'Gurgaon']);
        $employees = Employee::factory()->count(2)->create(['unit_name' => $noida->code]);

        $page = Livewire::test(ManageUnits::class)
            ->mountAction(TestAction::make('inactivate')->table($noida))
            ->assertMountedActionModalSee([
                '“Noida” is still in use',
                '2 employees still have this unit.',
                $employees->first()->emp_name,
                'Move 2 employees',
            ])
            ->assertMountedActionModalDontSee('Effective date')
            ->setActionData(['replacement' => $gurgaon->code])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('2 employees moved');

        $employees->each(fn (Employee $employee) => $this->assertSame($gurgaon->code, $employee->fresh()->unit_name));
        $this->assertTrue($noida->fresh()->is_active, 'Moving the employees must not switch the unit off by itself.');

        // The same pop-up is back, now on the effective date.
        $this->assertSame('inactivate', $page->instance()->mountedActions[0]['name'] ?? null);

        $page->assertMountedActionModalSee(['Inactivate “Noida”', 'Effective date'])
            ->setActionData(['inactive_from' => today()->toDateString()])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('“Noida” inactivated');

        $this->assertFalse($noida->fresh()->is_active);
        $this->assertTrue($noida->fresh()->inactive_from->isToday());
    }

    public function test_the_move_step_requires_a_destination(): void
    {
        $costCenter = CostCenter::query()->create(['name' => 'Old Desk']);
        CostCenter::query()->create(['name' => 'New Desk']);
        Employee::factory()->create(['cost_center' => $costCenter->code]);

        Livewire::test(ManageCostCenters::class)
            ->callAction(TestAction::make('inactivate')->table($costCenter))
            ->assertHasActionErrors(['replacement' => 'required']);

        $this->assertTrue($costCenter->fresh()->is_active);
    }

    public function test_with_no_other_active_option_the_move_step_cannot_be_submitted(): void
    {
        $only = CostCenter::query()->create(['name' => 'Only Desk']);
        CostCenter::query()->where('id', '!=', $only->id)->update(['is_active' => false]);
        CostCenter::forgetOptions();
        Employee::factory()->create(['cost_center' => $only->code]);

        Livewire::test(ManageCostCenters::class)
            ->mountAction(TestAction::make('inactivate')->table($only))
            ->assertMountedActionModalSee('There is no other active cost center to move them to.');
    }

    public function test_the_effective_date_cannot_be_in_the_future(): void
    {
        $unit = Unit::query()->create(['name' => 'Noida']);

        Livewire::test(ManageUnits::class)
            ->callAction(TestAction::make('inactivate')->table($unit), ['inactive_from' => today()->addDay()->toDateString()])
            ->assertHasActionErrors(['inactive_from']);

        $this->assertTrue($unit->fresh()->is_active);

        $this->expectException(RuntimeException::class);
        $unit->inactivate(today()->addDay());
    }

    public function test_an_option_still_held_is_never_switched_off_underneath_its_employees(): void
    {
        $unit = Unit::query()->create(['name' => 'Noida']);
        Employee::factory()->create(['unit_name' => $unit->code]);

        try {
            $unit->update(['is_active' => false]);
            $this->fail('A unit still held by an employee was switched off.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('still assigned to 1 employee', $exception->getMessage());
        }

        $this->assertTrue($unit->fresh()->is_active);
    }

    public function test_an_option_switched_off_without_a_date_gets_today(): void
    {
        $costCenter = CostCenter::query()->create(['name' => 'Closed Desk', 'is_active' => false]);

        $this->assertTrue($costCenter->fresh()->inactive_from->isToday());
    }

    public function test_activating_again_clears_the_effective_date(): void
    {
        $unit = Unit::query()->create(['name' => 'Noida']);
        $unit->inactivate(today()->subMonth());

        Livewire::test(ManageUnits::class)
            ->assertActionHidden(TestAction::make('inactivate')->table($unit))
            ->callAction(TestAction::make('activate')->table($unit))
            ->assertNotified('“Noida” activated');

        $unit->refresh();

        $this->assertTrue($unit->is_active);
        $this->assertNull($unit->inactive_from);
        $this->assertArrayHasKey($unit->code, Unit::activeOptions());
    }

    public function test_an_inactivated_option_still_counts_as_active_before_its_effective_date(): void
    {
        $unit = Unit::query()->create(['name' => 'Noida']);
        $stillActive = Unit::query()->create(['name' => 'Gurgaon']);
        $unit->inactivate(Carbon::parse('2026-10-15'));

        $this->assertTrue($unit->wasActiveOn(Carbon::parse('2026-10-14')));
        $this->assertFalse($unit->wasActiveOn(Carbon::parse('2026-10-15')));
        $this->assertTrue($stillActive->wasActiveOn(Carbon::parse('2026-10-15')));

        $this->assertContains($unit->id, Unit::query()->activeOn(Carbon::parse('2026-10-01'))->pluck('id'));
        $this->assertNotContains($unit->id, Unit::query()->activeOn(Carbon::parse('2026-10-20'))->pluck('id'));
        $this->assertContains($stillActive->id, Unit::query()->activeOn(Carbon::parse('2026-10-20'))->pluck('id'));
    }
}
