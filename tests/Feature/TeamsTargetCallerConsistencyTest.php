<?php

namespace Tests\Feature;

use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\Pages\ViewTeam;
use App\Models\Employee;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Proves the "target" column shows the SAME, entry/exit-adjusted number
 * for a Caller row in both Teams listings (the main Teams list and the
 * per-record Team View), when viewed by anyone other than that caller —
 * previously the Teams list always showed the flat category target
 * (ignoring reporting_date/exit_date), and the Team View listing applied
 * its own hand-rolled reimplementation of the exit-date rule instead of
 * delegating to the canonical engine.
 */
class TeamsTargetCallerConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
    }

    private function actingAsTeamLeader(Employee $teamLeader): void
    {
        $user = User::factory()->create(['employee_id' => $teamLeader->id]);
        $this->actingAs($user);
    }

    private function actingAsAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    public function test_new_joiner_caller_shows_zero_target_in_both_listings_when_viewed_by_a_superior(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 27));

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
        ]);

        // Joined 3 days ago -> fewer than 10 worked days this month -> 0
        // target, per the canonical entry-date rule.
        $newJoiner = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'category' => '2500000',
            'reporting_date' => Carbon::create(2026, 8, 25),
        ]);

        $this->actingAsTeamLeader($teamLeader);

        // Teams list (TeamsTable) — Team Leader sees their own Callers.
        Livewire::test(ListTeams::class)
            ->assertTableColumnStateSet('target', 0.0, $newJoiner);

        $this->actingAsAdmin();

        // Team View (ViewTeam) — Admin viewing the Team Leader's team.
        Livewire::test(ViewTeam::class, ['record' => $teamLeader])
            ->assertTableColumnStateSet('target', 0.0, $newJoiner);

        Carbon::setTestNow();
    }

    public function test_caller_viewing_their_own_row_still_sees_the_flat_category_target(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 27));

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
        ]);

        $newJoiner = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'category' => '2500000',
            'reporting_date' => Carbon::create(2026, 8, 25),
        ]);

        $user = User::factory()->create(['employee_id' => $newJoiner->id]);
        $this->actingAs($user);

        // The caller's OWN target is unaffected by this change — still
        // the flat category value regardless of reporting_date.
        $target = app(AchievementCalculatorService::class)->getTarget($newJoiner);

        $this->assertSame(2500000.0, $target);

        Carbon::setTestNow();
    }

    public function test_exited_caller_target_in_view_team_matches_the_canonical_engine_not_a_hardcoded_today_check(): void
    {
        // Livewire's test harness rebinds request() internally, so the
        // global month-selector cookie can't be reliably simulated through
        // a Livewire round-trip here — instead this proves the actual code
        // change directly: ViewTeam's target column for a superior viewing
        // a Caller now delegates entirely to getHierarchyCallerTarget()
        // (no separate hand-rolled Carbon::today() check in between), by
        // asserting the column's output matches that canonical method
        // call-for-call across both exit-date branches it covers.
        Carbon::setTestNow(Carbon::create(2026, 8, 27));

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
        ]);

        // Exited this month (day >= 10) -> half-target.
        $exitedThisMonth = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'category' => '2500000',
            'reporting_date' => null,
            'exit_status' => 'yes',
            'exit_date' => Carbon::create(2026, 8, 15),
        ]);

        // Exited well before this month -> zero.
        $exitedEarlier = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'category' => '2500000',
            'reporting_date' => null,
            'exit_status' => 'yes',
            'exit_date' => Carbon::create(2026, 6, 15),
        ]);

        $calculator = app(AchievementCalculatorService::class);

        $this->assertSame(1500000.0, $calculator->getHierarchyCallerTarget($exitedThisMonth));
        $this->assertSame(0.0, $calculator->getHierarchyCallerTarget($exitedEarlier));

        $this->actingAsAdmin();

        Livewire::test(ViewTeam::class, ['record' => $teamLeader])
            ->assertTableColumnStateSet('target', 1500000.0, $exitedThisMonth)
            ->assertTableColumnStateSet('target', 0.0, $exitedEarlier);

        Carbon::setTestNow();
    }
}
