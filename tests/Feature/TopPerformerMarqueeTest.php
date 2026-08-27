<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\TopPerformerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin (no linked Employee record) previously only ever saw the Top 5
 * Callers leaderboard. This verifies the admin marquee now combines Top 5
 * Callers, Top 5 Team Leaders, and Top 2 Managers (each ranked within its
 * own group), while each individual role's own marquee view is unchanged.
 */
class TopPerformerMarqueeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // TopPerformerService caches by designation; the array driver
        // persists across test methods within one PHPUnit process.
        Cache::flush();
    }

    private function callerWithAchievement(float $loanAmount, float $categoryTarget = 2500000): Employee
    {
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => (string) $categoryTarget,
            'exit_status' => 'no',
        ]);

        $this->sale($caller, $loanAmount);

        return $caller;
    }

    private function teamLeaderWithAchievement(float $loanAmount, float $categoryTarget = 2500000): Employee
    {
        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'exit_status' => 'no',
        ]);

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => (string) $categoryTarget,
            'superviser_id' => $teamLeader->id,
            'exit_status' => 'no',
        ]);

        $this->sale($caller, $loanAmount);

        return $teamLeader;
    }

    private function managerWithAchievement(float $loanAmount, float $categoryTarget = 2500000): Employee
    {
        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'exit_status' => 'no',
        ]);

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'exit_status' => 'no',
        ]);

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => (string) $categoryTarget,
            'superviser_id' => $teamLeader->id,
            'exit_status' => 'no',
        ]);

        $this->sale($caller, $loanAmount);

        return $manager;
    }

    private function sale(Employee $caller, float $loanAmount): void
    {
        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_loan_amount' => $loanAmount,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
        ]);
    }

    public function test_admin_top_performers_combine_top_5_callers_top_5_team_leaders_and_top_2_managers(): void
    {
        // 6 of each so the per-group cap (5/5/2) actually excludes the weakest.
        $callers = collect(range(1, 6))->map(fn ($i) => $this->callerWithAchievement($i * 1000000));
        $teamLeaders = collect(range(1, 6))->map(fn ($i) => $this->teamLeaderWithAchievement($i * 1000000));
        $managers = collect(range(1, 3))->map(fn ($i) => $this->managerWithAchievement($i * 1000000));

        $performers = (new TopPerformerService(new AchievementCalculatorService))
            ->getTopPerformers(null);

        $this->assertCount(12, $performers);

        $byDesignation = collect($performers)->groupBy('designation');

        $this->assertCount(5, $byDesignation[Employee::DESIGNATION_CALLER]);
        $this->assertCount(5, $byDesignation[Employee::DESIGNATION_TEAM_LEADER]);
        $this->assertCount(2, $byDesignation[Employee::DESIGNATION_MANAGER]);

        // Weakest performer (lowest loan amount => lowest percentage) in each
        // group is excluded by the cap.
        $names = collect($performers)->pluck('name');

        $this->assertFalse($names->contains($callers->first()->emp_name));
        $this->assertFalse($names->contains($teamLeaders->first()->emp_name));
        $this->assertFalse($names->contains($managers->first()->emp_name));

        $this->assertTrue($names->contains($callers->last()->emp_name));
        $this->assertTrue($names->contains($teamLeaders->last()->emp_name));
        $this->assertTrue($names->contains($managers->last()->emp_name));

        // Within each group, ranked by percentage descending.
        $callerPercentages = $byDesignation[Employee::DESIGNATION_CALLER]->pluck('percentage')->values();
        $this->assertSame($callerPercentages->sortDesc()->values()->all(), $callerPercentages->all());
    }

    public function test_admin_marquee_message_shows_all_three_grouped_sections(): void
    {
        collect(range(1, 5))->each(fn ($i) => $this->callerWithAchievement($i * 1000000));
        collect(range(1, 5))->each(fn ($i) => $this->teamLeaderWithAchievement($i * 1000000));
        $managers = collect(range(1, 2))->map(fn ($i) => $this->managerWithAchievement($i * 1000000));

        $admin = User::factory()->create(['employee_id' => null]);

        $this->actingAs($admin);

        $component = Livewire::test('top-performer-marquee');

        $component->assertSee('Top 5 Callers')
            ->assertSee('Top 5 Team Leaders')
            ->assertSee('Top 2 Managers')
            ->assertSee($managers->last()->emp_name);
    }

    public function test_caller_login_still_shows_the_top_5_callers_title(): void
    {
        $caller = $this->callerWithAchievement(3000000, 4000000);

        $user = User::factory()->create(['employee_id' => $caller->id]);

        $this->actingAs($user);

        Livewire::test('top-performer-marquee')
            ->assertSee('Top 5 Callers')
            ->assertSee($caller->emp_name)
            ->assertDontSee('Top 5 Team Leaders')
            ->assertDontSee('Top 2 Managers');
    }

    public function test_team_leader_login_still_shows_the_top_3_team_leaders_title(): void
    {
        $teamLeader = $this->teamLeaderWithAchievement(3000000);

        $user = User::factory()->create(['employee_id' => $teamLeader->id]);

        $this->actingAs($user);

        Livewire::test('top-performer-marquee')
            ->assertSee('Top 3 Team Leaders')
            ->assertSee($teamLeader->emp_name)
            ->assertDontSee('Top 5 Callers');
    }

    public function test_manager_login_still_shows_the_top_3_managers_title(): void
    {
        $manager = $this->managerWithAchievement(3000000);

        $user = User::factory()->create(['employee_id' => $manager->id]);

        $this->actingAs($user);

        Livewire::test('top-performer-marquee')
            ->assertSee('Top 3 Managers')
            ->assertSee($manager->emp_name)
            ->assertDontSee('Top 5 Callers');
    }
}
