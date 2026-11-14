<?php

namespace Tests\Feature;

use App\Enums\EmployeeLookupSection;
use App\Filament\Pages\EmployeeLookup;
use App\Livewire\EmployeeQuickSearch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeLookupAccess;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The topbar "Find employee" search and the Employee Lookup page it opens:
 * the Admin finds everyone, everybody else themselves and their team down
 * the line — never a boss, a peer or another branch.
 */
class EmployeeLookupTest extends TestCase
{
    use RefreshDatabase;

    private Employee $cluster;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller;

    private Employee $peerTeamLeader;

    private Employee $peerCaller;

    private Employee $otherManager;

    private Employee $otherCaller;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach (['Admin', 'IT', 'Caller', 'Team Leader', 'Manager'] as $role) {
            Role::findOrCreate($role);
        }

        $this->cluster = $this->employee('Cluster Kapoor', 'CL-001', Employee::DESIGNATION_CLUSTER);
        $this->manager = $this->employee('Meera Manager', 'MG-001', Employee::DESIGNATION_MANAGER, ['cluster_id' => $this->cluster->id]);
        $this->teamLeader = $this->employee('Tarun Leader', 'TL-001', Employee::DESIGNATION_TEAM_LEADER, ['manager_id' => $this->manager->id]);
        $this->caller = $this->employee('Asha Rao', 'FYN-0142', Employee::DESIGNATION_CALLER, ['superviser_id' => $this->teamLeader->id]);
        $this->peerTeamLeader = $this->employee('Pooja Peer', 'TL-002', Employee::DESIGNATION_TEAM_LEADER, ['manager_id' => $this->manager->id]);
        $this->peerCaller = $this->employee('Asha Verma', 'FYN-0200', Employee::DESIGNATION_CALLER, ['superviser_id' => $this->peerTeamLeader->id]);
        $this->otherManager = $this->employee('Other Manager', 'MG-002', Employee::DESIGNATION_MANAGER);
        $this->otherCaller = $this->employee('Asha Outsider', 'FYN-0999', Employee::DESIGNATION_CALLER, ['manager_id' => $this->otherManager->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(string $name, string $empId, int $designation, array $attributes = []): Employee
    {
        return Employee::factory()->create([
            'emp_name' => $name,
            'emp_id' => $empId,
            'designation' => $designation,
            ...$attributes,
        ]);
    }

    private function loginAs(?Employee $employee, string $role): User
    {
        $user = User::factory()->create(['employee_id' => $employee?->id]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return list<string>
     */
    private function searchNames(User $user, string $term): array
    {
        return EmployeeLookupAccess::search($user, $term)->pluck('emp_name')->sort()->values()->all();
    }

    public function test_the_admin_finds_everyone_including_other_branches_and_exited_employees(): void
    {
        $this->otherCaller->update(['exit_status' => 'yes']);
        $admin = $this->loginAs(null, 'Admin');

        $this->assertSame(['Asha Outsider', 'Asha Rao', 'Asha Verma'], $this->searchNames($admin, 'asha'));
    }

    public function test_a_team_leader_finds_themselves_and_their_callers_only(): void
    {
        $user = $this->loginAs($this->teamLeader, 'Team Leader');

        $this->assertSame(['Asha Rao'], $this->searchNames($user, 'asha'));
        $this->assertSame(['Tarun Leader'], $this->searchNames($user, 'tarun'));
        $this->assertSame([], $this->searchNames($user, 'meera'), 'Their boss is not down the line.');
        $this->assertSame([], $this->searchNames($user, 'pooja'), 'A peer Team Leader is not down the line.');
    }

    public function test_a_manager_finds_their_whole_branch_down_the_line(): void
    {
        $user = $this->loginAs($this->manager, 'Manager');

        $this->assertSame(['Asha Rao', 'Asha Verma'], $this->searchNames($user, 'asha'));
        $this->assertSame([], $this->searchNames($user, 'cluster'));
    }

    public function test_a_manager_still_finds_callers_under_an_exited_team_leader(): void
    {
        $this->teamLeader->update(['exit_status' => 'yes']);
        $user = $this->loginAs($this->manager, 'Manager');

        $this->assertContains('Asha Rao', $this->searchNames($user, 'asha'));
        $this->assertContains('Tarun Leader', $this->searchNames($user, 'tarun'));
    }

    public function test_a_caller_finds_only_themselves(): void
    {
        $user = $this->loginAs($this->caller, 'Caller');

        $this->assertSame(['Asha Rao'], $this->searchNames($user, 'a'));
        $this->assertSame([], $this->searchNames($user, 'tarun'));
    }

    public function test_the_search_matches_the_employee_id_and_labels_name_with_id(): void
    {
        $admin = $this->loginAs(null, 'Admin');

        $this->assertSame(
            [$this->caller->id => 'Asha Rao (FYN-0142)'],
            EmployeeLookupAccess::searchOptions($admin, 'fyn-0142'),
        );
    }

    public function test_a_blank_search_returns_nothing(): void
    {
        $admin = $this->loginAs(null, 'Admin');

        $this->assertSame([], $this->searchNames($admin, '   '));
    }

    public function test_a_login_without_an_employee_record_cannot_search(): void
    {
        $user = $this->loginAs(null, 'Caller');

        $this->assertFalse(EmployeeLookupAccess::canSearch($user));
        $this->assertFalse(EmployeeLookup::canAccess());
    }

    public function test_an_it_restricted_login_gets_no_lookup(): void
    {
        $this->loginAs($this->manager, 'IT');

        $this->assertFalse(EmployeeLookup::canAccess());
    }

    public function test_the_topbar_dropdown_lists_name_and_employee_id_of_the_team(): void
    {
        $this->loginAs($this->teamLeader, 'Team Leader');

        Livewire::test(EmployeeQuickSearch::class)
            ->set('search', 'asha')
            ->assertSee('Asha Rao')
            ->assertSee('(FYN-0142)')
            ->assertSee(EmployeeLookup::getUrl(['employee' => $this->caller->id]), false)
            ->assertDontSee('Asha Verma')
            ->assertDontSee('Asha Outsider');
    }

    public function test_the_topbar_dropdown_says_when_nothing_matches(): void
    {
        $this->loginAs($this->caller, 'Caller');

        Livewire::test(EmployeeQuickSearch::class)
            ->set('search', 'meera')
            ->assertSee('No employee in your view matches');
    }

    public function test_the_topbar_carries_the_find_employee_box(): void
    {
        $this->loginAs(null, 'Admin');

        $this->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee('fynn-employee-search', false)
            ->assertSee('Find employee…');
    }

    public function test_ctrl_e_focuses_the_find_employee_box(): void
    {
        $this->loginAs($this->caller, 'Caller');

        $html = Livewire::test(EmployeeQuickSearch::class)->html();

        $this->assertStringContainsString('x-on:keydown.window=', $html);
        $this->assertStringContainsString("\$event.key.toLowerCase() === 'e'", $html);
        $this->assertStringContainsString('focusSearch()', $html);
        $this->assertStringContainsString('x-ref="input"', $html);
        $this->assertStringContainsString('aria-keyshortcuts="Control+E Meta+E"', $html);
        $this->assertStringContainsString('<kbd class="fynn-employee-search-kbd">Ctrl E</kbd>', $html);
    }

    public function test_picking_an_employee_opens_their_profile(): void
    {
        $this->loginAs($this->teamLeader, 'Team Leader');

        Livewire::withQueryParams(['employee' => $this->caller->id])
            ->test(EmployeeLookup::class)
            ->assertOk()
            ->assertSet('employee.id', $this->caller->id)
            ->assertSet('section', EmployeeLookupSection::Profile->value)
            ->assertSee('Asha Rao (FYN-0142)')
            ->assertSee('Reports To')
            ->assertSee('Tarun Leader (Team Leader)')
            ->assertSee('At a glance');
    }

    public function test_an_employee_outside_the_viewers_team_cannot_be_opened(): void
    {
        $this->loginAs($this->teamLeader, 'Team Leader');

        Livewire::withQueryParams(['employee' => $this->peerCaller->id])
            ->test(EmployeeLookup::class)
            ->assertSet('employee', null)
            ->assertSee('That employee was not found, or is outside your team.')
            ->assertDontSee('Asha Verma');
    }

    public function test_the_dropdown_on_the_page_moves_to_the_picked_profile_and_refuses_outsiders(): void
    {
        $this->loginAs($this->teamLeader, 'Team Leader');

        Livewire::test(EmployeeLookup::class)
            ->set('data.employee', $this->caller->id)
            ->assertRedirect(EmployeeLookup::getUrl(['employee' => $this->caller->id, 'section' => 'profile']));

        Livewire::test(EmployeeLookup::class)
            ->set('data.employee', $this->peerCaller->id)
            ->assertNoRedirect();
    }

    public function test_every_view_of_an_employee_renders(): void
    {
        $this->loginAs(null, 'Admin');

        Customer::factory()->create(['employee_id' => $this->caller->id, 'assign_to' => $this->caller->id]);

        foreach (EmployeeLookupSection::cases() as $section) {
            Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => $section->value])
                ->test(EmployeeLookup::class)
                ->assertOk()
                ->assertSet('section', $section->value)
                ->assertSee($section->label());
        }
    }

    public function test_an_unknown_view_falls_back_to_the_profile(): void
    {
        $this->loginAs(null, 'Admin');

        Livewire::withQueryParams(['employee' => $this->caller->id, 'section' => 'nonsense'])
            ->test(EmployeeLookup::class)
            ->assertSet('section', EmployeeLookupSection::Profile->value);
    }

    public function test_the_eligible_and_not_eligible_otp_lists_split_the_teams_customers(): void
    {
        $this->loginAs($this->teamLeader, 'Team Leader');

        $eligible = Customer::factory()->create([
            'customer_name' => 'Eligible Erin',
            'employee_id' => $this->caller->id,
            'assign_to' => $this->caller->id,
            'eligibility_status' => 'eligible',
        ]);
        $notEligible = Customer::factory()->create([
            'customer_name' => 'Rejected Ravi',
            'employee_id' => $this->caller->id,
            'assign_to' => $this->caller->id,
            'eligibility_status' => 'not_eligible',
        ]);
        $outsider = Customer::factory()->create([
            'customer_name' => 'Peer Priya',
            'employee_id' => $this->peerCaller->id,
            'assign_to' => $this->peerCaller->id,
            'eligibility_status' => 'eligible',
        ]);

        Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => EmployeeLookupSection::EligibleOtp->value])
            ->test(EmployeeLookup::class)
            ->assertCanSeeTableRecords([$eligible])
            ->assertCanNotSeeTableRecords([$notEligible, $outsider]);

        Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => EmployeeLookupSection::NotEligibleOtp->value])
            ->test(EmployeeLookup::class)
            ->assertCanSeeTableRecords([$notEligible])
            ->assertCanNotSeeTableRecords([$eligible, $outsider]);

        Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => EmployeeLookupSection::Customers->value])
            ->test(EmployeeLookup::class)
            ->assertCanSeeTableRecords([$eligible, $notEligible])
            ->assertCanNotSeeTableRecords([$outsider]);
    }

    public function test_the_hierarchy_view_links_reports_but_not_bosses_outside_the_viewers_reach(): void
    {
        $this->loginAs($this->teamLeader, 'Team Leader');

        $hierarchy = Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => EmployeeLookupSection::Hierarchy->value])
            ->test(EmployeeLookup::class)
            ->assertSee('Meera Manager')
            ->assertSee('Asha Rao')
            ->instance()
            ->hierarchy;

        $boss = collect($hierarchy['chain'])->firstWhere('id', $this->manager->id);
        $report = collect($hierarchy['reports'])->firstWhere('id', $this->caller->id);

        $this->assertNull($boss['url']);
        $this->assertSame(
            EmployeeLookup::getUrl(['employee' => $this->caller->id, 'section' => 'profile']),
            $report['url'],
        );
    }
}
