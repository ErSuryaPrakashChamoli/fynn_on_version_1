<?php

namespace Tests\Feature;

use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\CostCenters\Pages\ManageCostCenters;
use App\Filament\Resources\Designations\DesignationResource;
use App\Filament\Resources\Designations\Pages\ManageDesignations;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Positions\Pages\ManagePositions;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\TargetCategories\Pages\ManageTargetCategories;
use App\Filament\Resources\TargetCategories\TargetCategoryResource;
use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Filament\Resources\Units\UnitResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\CostCenter;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Position;
use App\Models\TargetCategory;
use App\Models\Unit;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Designation, Position, Target Category, Cost Center, Unit and Role are
 * admin-maintained lists (Employee Setup), not hardcoded dropdowns.
 */
class EmployeeSetupListsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Role::findOrCreate('Admin');
    }

    /**
     * Role's guardable-column list is cached per process from before the
     * is_active migration ran, so mass assignment would drop the flag here.
     */
    private function inactiveRole(string $name): Role
    {
        $role = Role::create(['name' => $name]);
        $role->forceFill(['is_active' => false])->save();

        return $role;
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('Admin');
    }

    public function test_the_lists_start_with_the_values_that_were_hardcoded(): void
    {
        $this->assertSame('Team Leader', Employee::designationOptions()[Employee::DESIGNATION_TEAM_LEADER]);
        $this->assertSame('Other Bank Support', Employee::designationOptions()[Employee::DESIGNATION_OTHER_BANK_SUPPORT]);
        $this->assertSame('Gold', TargetCategory::labelFor('3000000'));
        $this->assertSame('Alpha', TargetCategory::labelFor('team_leader'));
        $this->assertSame('Kanak Kumar', CostCenter::labelFor('kanak_kumar'));
        $this->assertSame('Rohit Sharma', Unit::labelFor('rohit_sharma'));
    }

    public function test_only_an_admin_can_open_the_setup_screens(): void
    {
        $resources = [DesignationResource::class, PositionResource::class, TargetCategoryResource::class, CostCenterResource::class, UnitResource::class, RoleResource::class];

        $this->actingAs(User::factory()->create()->assignRole(Role::findOrCreate('Caller')));

        foreach ($resources as $resource) {
            $this->assertFalse($resource::canAccess(), $resource);
        }

        $this->actingAs($this->admin());

        foreach ($resources as $resource) {
            $this->assertTrue($resource::canAccess(), $resource);
            $this->get($resource::getUrl())->assertOk();
        }
    }

    public function test_admin_can_add_rename_and_delete_a_cost_center_and_a_unit(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageCostCenters::class)
            ->callAction('create', ['name' => 'Sunita Rawat'])
            ->assertHasNoActionErrors();

        $costCenter = CostCenter::query()->where('name', 'Sunita Rawat')->sole();
        $this->assertSame('sunita_rawat', $costCenter->code);

        Livewire::test(ManageCostCenters::class)
            ->callAction(TestAction::make(EditAction::class)->table($costCenter), ['name' => 'Sunita R. Rawat'])
            ->assertHasNoActionErrors();

        $this->assertSame('sunita_rawat', $costCenter->fresh()->code);
        $this->assertSame('Sunita R. Rawat', CostCenter::labelFor('sunita_rawat'));

        Livewire::test(ManageCostCenters::class)
            ->callAction(TestAction::make('delete')->table($costCenter));

        $this->assertModelMissing($costCenter);

        Livewire::test(ManageUnits::class)
            ->callAction('create', ['name' => 'Dehradun'])
            ->assertHasNoActionErrors();

        $this->assertArrayHasKey('dehradun', Unit::options());
    }

    public function test_a_duplicate_name_is_refused(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageUnits::class)
            ->callAction('create', ['name' => 'Kanak Kumar'])
            ->assertHasActionErrors(['name' => 'unique']);
    }

    public function test_an_option_held_by_an_employee_is_never_deleted_out_from_under_them(): void
    {
        $unit = Unit::query()->create(['name' => 'Noida']);
        Employee::factory()->create(['unit_name' => $unit->code]);

        $this->expectException(RuntimeException::class);
        $unit->delete();
    }

    public function test_deleting_an_option_in_use_asks_where_to_move_its_employees_first(): void
    {
        $this->actingAs($this->admin());

        $noida = Unit::query()->create(['name' => 'Noida']);
        $gurgaon = Unit::query()->create(['name' => 'Gurgaon']);
        $employees = Employee::factory()->count(2)->create(['unit_name' => $noida->code]);

        Livewire::test(ManageUnits::class)
            ->mountAction(TestAction::make('delete')->table($noida))
            ->assertMountedActionModalSee([
                '“Noida” is still in use',
                '2 employees still have this unit.',
                $employees->first()->emp_name,
                'Move 2 employees & delete',
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['replacement' => 'required']);

        $this->assertModelExists($noida);

        Livewire::test(ManageUnits::class)
            ->callAction(TestAction::make('delete')->table($noida), ['replacement' => $gurgaon->code])
            ->assertHasNoActionErrors()
            ->assertNotified('“Noida” deleted');

        $this->assertModelMissing($noida);
        $employees->each(fn (Employee $employee) => $this->assertSame($gurgaon->code, $employee->fresh()->unit_name));
    }

    public function test_an_unused_option_is_deleted_after_a_plain_confirmation(): void
    {
        $this->actingAs($this->admin());

        $designation = Designation::query()->create(['name' => 'Intern']);

        Livewire::test(ManageDesignations::class)
            ->mountAction(TestAction::make('delete')->table($designation))
            ->assertMountedActionModalSee(['Delete “Intern”?', 'Nobody holds this designation.'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertModelMissing($designation);
    }

    public function test_employees_cannot_be_moved_to_an_inactive_option(): void
    {
        $this->actingAs($this->admin());

        $source = CostCenter::query()->create(['name' => 'Old Desk']);
        $inactive = CostCenter::query()->create(['name' => 'Closed Desk', 'is_active' => false]);
        Employee::factory()->create(['cost_center' => $source->code]);

        $this->assertArrayNotHasKey($inactive->code, $source->replacementOptions());

        Livewire::test(ManageCostCenters::class)
            ->callAction(TestAction::make('delete')->table($source), ['replacement' => $inactive->code])
            ->assertHasActionErrors(['replacement']);

        $this->assertModelExists($source);
    }

    public function test_a_position_in_use_can_only_be_emptied_into_a_position_outside_the_hierarchy(): void
    {
        $this->actingAs($this->admin());

        $trainer = Position::query()->create(['name' => 'Trainer']);
        $coach = Position::query()->create(['name' => 'Coach']);
        $employee = Employee::factory()->create(['designation' => $trainer->id]);

        $replacements = $trainer->replacementOptions();

        $this->assertArrayHasKey($coach->id, $replacements);
        $this->assertArrayNotHasKey(Employee::DESIGNATION_CALLER, $replacements);
        $this->assertArrayNotHasKey(Employee::DESIGNATION_MANAGER, $replacements);

        Livewire::test(ManagePositions::class)
            ->callAction(TestAction::make('delete')->table($trainer), ['replacement' => $coach->id])
            ->assertHasNoActionErrors();

        $this->assertModelMissing($trainer);
        $this->assertSame($coach->id, $employee->fresh()->designation);
    }

    public function test_the_active_toggle_hides_an_option_from_new_choices_but_keeps_it_for_its_holders(): void
    {
        $this->actingAs($this->admin());

        $designation = Designation::query()->create(['name' => 'Field Officer']);

        Livewire::test(ManageDesignations::class)
            ->assertTableColumnExists('is_active')
            ->call('updateTableColumnState', 'is_active', (string) $designation->getKey(), false);

        $this->assertFalse($designation->fresh()->is_active);
        $this->assertArrayNotHasKey('Field Officer', Designation::activeOptions());
        $this->assertArrayNotHasKey('Field Officer', Designation::optionsIncluding(null));
        $this->assertSame('Field Officer', Designation::optionsIncluding('Field Officer')['Field Officer']);
        $this->assertSame('Field Officer', Designation::labelFor('Field Officer'));
    }

    public function test_renaming_a_designation_renames_it_on_every_employee(): void
    {
        $this->actingAs($this->admin());

        $designation = Designation::query()->create(['name' => 'Sourcing Specialist']);
        $employee = Employee::factory()->create(['position' => 'Sourcing Specialist']);

        Livewire::test(ManageDesignations::class)
            ->callAction(TestAction::make(EditAction::class)->table($designation), ['name' => 'Senior Sourcing Specialist'])
            ->assertHasNoActionErrors();

        $this->assertSame('Senior Sourcing Specialist', $employee->fresh()->position);
    }

    public function test_built_in_positions_can_be_renamed_but_not_deleted(): void
    {
        $this->actingAs($this->admin());

        $caller = Position::query()->findOrFail(Employee::DESIGNATION_CALLER);

        $this->assertFalse(PositionResource::canDelete($caller));

        Livewire::test(ManagePositions::class)
            ->callAction(TestAction::make(EditAction::class)->table($caller), ['name' => 'Sales Caller'])
            ->assertHasNoActionErrors();

        $this->assertSame('Sales Caller', Employee::designationOptions()[Employee::DESIGNATION_CALLER]);
    }

    public function test_an_added_position_sits_outside_the_hierarchy_and_can_be_deleted(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManagePositions::class)
            ->callAction('create', ['name' => 'Trainer'])
            ->assertHasNoActionErrors();

        $trainer = Position::query()->where('name', 'Trainer')->sole();

        $this->assertFalse($trainer->is_system);
        $this->assertNotContains($trainer->id, [1, 2, 3, 5, 7, 9, 11]);
        $this->assertSame(0, Employee::designationRank($trainer->id));
        $this->assertTrue(PositionResource::canDelete($trainer));
    }

    public function test_a_target_category_amount_drives_the_employee_target(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageTargetCategories::class)
            ->callAction('create', ['name' => 'Platinum', 'target_amount' => '40,00,000'])
            ->assertHasNoActionErrors();

        $platinum = TargetCategory::query()->where('name', 'Platinum')->sole();
        $this->assertSame(4000000, $platinum->target_amount);

        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'category' => $platinum->code]);
        $this->assertSame(4000000, $caller->target_amount);

        // Raising an existing category raises everyone in it.
        $gold = TargetCategory::query()->where('code', '3000000')->sole();

        Livewire::test(ManageTargetCategories::class)
            ->callAction(TestAction::make(EditAction::class)->table($gold), ['name' => 'Gold', 'target_amount' => '32,00,000'])
            ->assertHasNoActionErrors();

        $goldCaller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'category' => '3000000']);
        $this->assertSame(3200000, $goldCaller->target_amount);

        $categoryTarget = new ReflectionMethod(AchievementCalculatorService::class, 'categoryTarget');
        $this->assertSame(3200000.0, $categoryTarget->invoke(app(AchievementCalculatorService::class), $goldCaller));
    }

    public function test_the_employee_form_offers_the_managed_lists(): void
    {
        $this->actingAs($this->admin());

        Designation::query()->create(['name' => 'Relationship Officer']);
        $unit = Unit::query()->create(['name' => 'Haridwar']);
        $costCenter = CostCenter::query()->create(['name' => 'Asha Rao']);
        $category = TargetCategory::query()->create(['name' => 'Bronze', 'target_amount' => 2000000]);
        $boss = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER, 'exit_status' => 'no']);

        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'emp_id' => 'FYN-9001',
                'emp_name' => 'Ravi Negi',
                'email' => 'ravi.negi@example.com',
                'position' => 'Relationship Officer',
                'designation' => Employee::DESIGNATION_CALLER,
                'category' => $category->code,
                'reports_to' => $boss->id,
                'cost_center' => $costCenter->code,
                'unit_name' => $unit->code,
                'exit_status' => 'no',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('employees', [
            'emp_id' => 'FYN-9001',
            'position' => 'Relationship Officer',
            'category' => $category->code,
            'cost_center' => $costCenter->code,
            'unit_name' => $unit->code,
        ]);
    }

    public function test_admin_can_add_and_delete_a_custom_role_but_not_touch_built_in_ones(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageRoles::class)
            ->callAction('create', ['name' => 'Quality Auditor'])
            ->assertHasNoActionErrors();

        $role = Role::findByName('Quality Auditor');

        $this->assertTrue(RoleResource::canEdit($role));
        $this->assertTrue(RoleResource::canDelete($role));

        $admin = Role::findByName('Admin');
        $this->assertFalse(RoleResource::canEdit($admin));
        $this->assertFalse(RoleResource::canDelete($admin));

        Livewire::test(ManageRoles::class)
            ->assertActionHidden(TestAction::make(EditAction::class)->table($admin))
            ->assertActionHidden(TestAction::make('delete')->table($admin));
    }

    public function test_deleting_a_role_in_use_moves_its_logins_to_another_role_first(): void
    {
        $this->actingAs($this->admin());

        $auditor = Role::create(['name' => 'Quality Auditor']);
        $reviewer = Role::create(['name' => 'Reviewer']);
        $this->inactiveRole('Retired Role');
        Role::findOrCreate('Caller');
        $user = User::factory()->create()->assignRole($auditor);

        $replacements = RoleResource::replacementOptions($auditor);
        $this->assertContains('Reviewer', $replacements);
        $this->assertNotContains('Retired Role', $replacements);
        $this->assertNotContains('Caller', $replacements);
        $this->assertNotContains('Quality Auditor', $replacements);

        Livewire::test(ManageRoles::class)
            ->mountAction(TestAction::make('delete')->table($auditor))
            ->assertMountedActionModalSee(['“Quality Auditor” is still in use', $user->email])
            ->setActionData(['replacement' => $reviewer->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertModelMissing($auditor);
        $this->assertSame(['Reviewer'], $user->fresh()->getRoleNames()->all());
    }

    public function test_an_inactive_role_is_not_offered_on_the_user_form(): void
    {
        $this->inactiveRole('Retired Role');
        Role::create(['name' => 'Reviewer']);

        $this->actingAs($this->admin());

        $this->get(UserResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Reviewer')
            ->assertDontSee('Retired Role');
    }
}
