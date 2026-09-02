<?php

namespace Tests\Feature;

use App\Filament\Widgets\IncentiveStats;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use NumberFormatter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * End-to-end proof that the actual IncentiveStats Livewire widget — not
 * just the underlying calculator in isolation — renders the correct
 * hierarchy-scoped number for every role it's visible to. Production
 * widget logic is not touched by this test file.
 */
class IncentiveStatsWidgetScopeTest extends TestCase
{
    use RefreshDatabase;

    private Employee $cluster;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller1;

    private Employee $caller2;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->cluster = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $this->manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $this->cluster->id,
        ]);
        $this->teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
        ]);
        $this->caller1 = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
        ]);
        $this->caller2 = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
        ]);

        // A sibling branch whose numbers must never appear in this
        // branch's TL/Manager/Cluster totals.
        $otherCaller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);
        $this->customer($otherCaller, 5000000);

        $this->customer($this->caller1, 1000000);
        $this->customer($this->caller2, 2000000);
    }

    private function customer(Employee $caller, float $loanAmount): Customer
    {
        return Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_loan_amount' => $loanAmount,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'disbursal_date' => now(),
        ]);
    }

    private function userFor(Employee $employee): User
    {
        return User::factory()->create(['employee_id' => $employee->id]);
    }

    private function formatted(float $amount): string
    {
        $formatter = new NumberFormatter('en_IN', NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);

        return $formatter->formatCurrency($amount, 'INR');
    }

    public function test_caller_widget_shows_only_their_own_achievement(): void
    {
        $this->actingAs($this->userFor($this->caller1));

        $expected = (new AchievementCalculatorService)->getPerformance($this->caller1)['count_achievement'];
        $this->assertSame(1000000.0, $expected);

        Livewire::test(IncentiveStats::class)
            ->assertSee($this->formatted($expected));
    }

    public function test_team_leader_widget_shows_the_whole_team_not_just_one_caller(): void
    {
        $this->actingAs($this->userFor($this->teamLeader));

        $expected = (new AchievementCalculatorService)->getPerformance($this->teamLeader)['count_achievement'];
        $this->assertSame(3000000.0, $expected);

        Livewire::test(IncentiveStats::class)
            ->assertSee($this->formatted($expected))
            ->assertDontSee($this->formatted(1000000.0));
    }

    public function test_manager_widget_shows_the_whole_team(): void
    {
        $this->actingAs($this->userFor($this->manager));

        $expected = (new AchievementCalculatorService)->getPerformance($this->manager)['count_achievement'];
        $this->assertSame(3000000.0, $expected);

        Livewire::test(IncentiveStats::class)
            ->assertSee($this->formatted($expected));
    }

    public function test_cluster_manager_widget_shows_the_whole_cluster(): void
    {
        $this->actingAs($this->userFor($this->cluster));

        $expected = (new AchievementCalculatorService)->getPerformance($this->cluster)['count_achievement'];
        $this->assertSame(3000000.0, $expected);

        Livewire::test(IncentiveStats::class)
            ->assertSee($this->formatted($expected));
    }

    public function test_admin_widget_shows_the_whole_company_including_the_other_branch(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $expected = (new AchievementCalculatorService)->getPerformance(null)['count_achievement'];
        $this->assertSame(8000000.0, $expected);

        Livewire::test(IncentiveStats::class)
            ->assertSee($this->formatted($expected));
    }

    public function test_admin_widget_also_shows_the_whole_company_when_admin_has_a_linked_employee_record(): void
    {
        $adminEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_ADMIN]);

        $admin = User::factory()->create(['employee_id' => $adminEmployee->id]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $expected = (new AchievementCalculatorService)->getPerformance($adminEmployee)['count_achievement'];
        $this->assertSame(8000000.0, $expected);

        Livewire::test(IncentiveStats::class)
            ->assertSee($this->formatted($expected));
    }
}
