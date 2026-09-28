<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EmployeeHierarchy;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Employee;
use App\Models\User;
use App\Support\HierarchyHelper;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An IT login sees exactly two modules — the Reporting Hierarchy
 * (company-wide) and the Help Desk — and nothing else, by sidebar or URL.
 * See App\Support\ItModuleAccess.
 */
class ItRoleModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'IT', 'Caller'] as $role) {
            Role::findOrCreate($role);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function itUser(): User
    {
        return User::factory()->create()->assignRole('IT');
    }

    public function test_the_sidebar_shows_only_the_hierarchy_and_the_help_desk(): void
    {
        $this->actingAs($this->itUser());

        $response = $this->get(EmployeeHierarchy::getUrl());

        $response->assertOk();
        $response->assertSee(EmployeeHierarchy::getUrl());
        $response->assertSee(ComplaintResource::getUrl());
        $response->assertDontSee(CustomerResource::getUrl());
        $response->assertDontSee(UserResource::getUrl());
        $response->assertDontSee('top-performer-marquee');
    }

    public function test_every_other_url_lands_on_the_hierarchy_page(): void
    {
        $this->actingAs($this->itUser());

        foreach ([Dashboard::getUrl(), CustomerResource::getUrl(), UserResource::getUrl()] as $url) {
            $this->get($url)->assertRedirect(EmployeeHierarchy::getUrl());
        }

        $this->get(ComplaintResource::getUrl())->assertOk();
        $this->assertFalse(UserResource::canAccess());
        $this->assertTrue(EmployeeHierarchy::canAccess());
    }

    public function test_it_sees_anyone_in_the_hierarchy_across_the_company(): void
    {
        $clusterA = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $callerA = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'cluster_id' => $clusterA->id]);
        $clusterB = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $callerB = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'cluster_id' => $clusterB->id]);

        $it = $this->itUser();
        $this->actingAs($it);

        $visible = HierarchyHelper::ownHierarchyIds($it);

        foreach ([$clusterA, $callerA, $clusterB, $callerB] as $employee) {
            $this->assertTrue($visible->contains($employee->id));
        }

        Livewire::test(EmployeeHierarchy::class)
            ->call('selectEmployee', $callerB->id)
            ->assertSee($callerB->emp_name)
            ->assertSee($clusterB->emp_name);
    }

    public function test_an_admin_who_also_holds_it_is_not_restricted(): void
    {
        $user = User::factory()->create()->assignRole(['Admin', 'IT']);
        $this->actingAs($user);

        $this->assertFalse($user->isRestrictedToItModules());
        $this->get(Dashboard::getUrl())->assertOk();
        $this->get(CustomerResource::getUrl())->assertOk();
    }

    public function test_a_caller_is_unaffected(): void
    {
        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);
        $this->actingAs(User::factory()->create(['employee_id' => $caller->id])->assignRole('Caller'));

        $this->followingRedirects()->get(Dashboard::getUrl())->assertOk();
    }
}
