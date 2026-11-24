<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\EmployeePositionChangeService;
use App\Services\Performance\EmployeeFigureBreakdownService;
use App\Services\ReportingLineService;
use App\Support\EmployeeLookupPeriod;
use App\Support\ReportingTimeline;
use App\Support\ReportingTree;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A promotion or demotion counts from its effective date, and that date
 * can be the real one: rows that only re-filed somebody under the same
 * people (e.g. "Follows X's designation change.") no longer hold it back,
 * and a change keyed in with the wrong date is corrected from the
 * Employee page ("Correct Effective Date").
 *
 * Cluster Manager K ── Team Leaders V, V2. V ── Callers P, X2, X3.
 * Everyone on Silver (₹25L). P is promoted to Team Leader under K from
 * 1 Nov 2026 — the case of Punit Kumar (ME0201).
 */
class PositionChangeEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-24';

    private const SILVER = '2500000';

    private const CALLER_TARGET = 2500000.0;

    private const TOP_UP = 3000000.0;

    private Employee $cluster;

    private Employee $teamLeader;

    private Employee $otherTeamLeader;

    private Employee $punit;

    private Employee $secondCaller;

    private Employee $thirdCaller;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 11:00'));

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->cluster = $this->employee(Employee::DESIGNATION_CLUSTER);
        $this->teamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->cluster);
        $this->otherTeamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->cluster);
        $this->punit = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeader, ['emp_id' => 'ME0201', 'doj' => '2026-05-15', 'reporting_date' => '2026-05-15']);
        $this->secondCaller = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeader);
        $this->thirdCaller = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeader);

        $this->admin = User::factory()->create()->assignRole(Role::findOrCreate('Admin'));
        $this->actingAs($this->admin);
    }

    /*
    |--------------------------------------------------------------------------
    | Back-dating past rows that moved nobody
    |--------------------------------------------------------------------------
    */

    public function test_a_promotion_can_be_dated_before_a_row_that_did_not_move_them(): void
    {
        $noOp = $this->sameBossesRow($this->punit, '2026-11-14');

        $this->assertSame('2026-05-15', $this->service()->earliestEffectiveDate($this->punit, true, false)->toDateString());

        $this->promotePunit('2026-11-01');

        $this->assertModelMissing($noOp);
        $this->assertSame('2026-11-01', $this->designationRow($this->punit)->effective_date->toDateString());
        $this->assertSame($this->teamLeader->id, ReportingTree::load(Carbon::parse('2026-10-31'))->bossId($this->punit->id));
        $this->assertSame($this->cluster->id, ReportingTree::load(Carbon::parse('2026-11-01'))->bossId($this->punit->id));
        $this->assertSame($this->cluster->id, ReportingTree::load(Carbon::parse('2026-11-20'))->bossId($this->punit->id));
    }

    public function test_targets_follow_the_promotion_from_its_effective_date(): void
    {
        $this->sameBossesRow($this->punit, '2026-11-14');
        $this->promotePunit('2026-11-01');

        $calculator = new AchievementCalculatorService;
        $october = Carbon::create(2026, 10, 1);
        $november = Carbon::create(2026, 11, 1);

        // October: still a caller in V's full team.
        $this->assertSame(self::CALLER_TARGET, $calculator->getTarget($this->punit, $october));
        $this->assertSame(3 * self::CALLER_TARGET, $calculator->getTarget($this->teamLeader, $october));

        // November: a Team Leader with no callers yet, and V down to two.
        $this->assertSame(self::TOP_UP, $calculator->getTarget($this->punit, $november));
        $this->assertSame(2 * self::CALLER_TARGET + self::TOP_UP, $calculator->getTarget($this->teamLeader, $november));
        // K: X2 and X3, plus the top-ups of V (2 callers), P (none) and V2 (none).
        $this->assertSame(2 * self::CALLER_TARGET + 3 * self::TOP_UP, $calculator->getTarget($this->cluster, $november));
    }

    public function test_the_employee_lookup_explains_each_month_as_the_position_held_then(): void
    {
        $this->promotePunit('2026-11-01');

        $breakdowns = app(EmployeeFigureBreakdownService::class);

        $october = $breakdowns->target($this->punit->refresh(), EmployeeLookupPeriod::forMonth(Carbon::create(2026, 10, 1)));
        $november = $breakdowns->target($this->punit, EmployeeLookupPeriod::forMonth(Carbon::create(2026, 11, 1)));

        $this->assertSame('caller', $october['kind']);
        $this->assertSame(self::CALLER_TARGET, $october['total']);
        $this->assertSame('team', $november['kind']);
        $this->assertSame(self::TOP_UP, $november['total']);
    }

    public function test_a_real_move_after_the_date_still_holds_a_promotion_back(): void
    {
        app(ReportingLineService::class)->reportTo($this->punit, $this->otherTeamLeader->id, '2026-11-10', ReportingLineService::CHANGE_TRANSFER);

        try {
            $this->promotePunit('2026-11-01');
            $this->fail('A promotion dated before a later move was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('10 Nov 2026', $exception->errors()['effective_date'][0]);
        }

        $this->assertSame(Employee::DESIGNATION_CALLER, $this->punit->fresh()->designation);
    }

    public function test_a_demoted_team_leader_keeps_their_team_target_for_the_months_before(): void
    {
        $this->service()->change(
            $this->teamLeader,
            Employee::DESIGNATION_CALLER,
            $this->otherTeamLeader->id,
            self::SILVER,
            Carbon::parse('2026-11-01'),
            null,
            $this->admin,
            teamBossId: $this->otherTeamLeader->id,
        );

        $calculator = new AchievementCalculatorService;

        $this->assertSame(3 * self::CALLER_TARGET, $calculator->getTarget($this->teamLeader->refresh(), Carbon::create(2026, 10, 1)));
        $this->assertSame(self::CALLER_TARGET, $calculator->getTarget($this->teamLeader, Carbon::create(2026, 11, 1)));
    }

    /*
    |--------------------------------------------------------------------------
    | Correcting the date of a change already recorded
    |--------------------------------------------------------------------------
    */

    public function test_a_promotion_keyed_in_with_the_wrong_date_is_corrected_from_the_view_page(): void
    {
        $noOp = $this->sameBossesRow($this->punit, '2026-11-14');
        $this->promotePunit(self::TODAY);

        Livewire::test(ViewEmployee::class, ['record' => $this->punit->id])
            ->mountAction('correctChangeDate')
            ->assertMountedActionModalSee(['Promotion: Caller → Team Leader.', 'Recorded as effective from 24 Nov 2026.'])
            ->setActionData(['effective_date' => '2026-11-01'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $designation = $this->designationRow($this->punit);

        $this->assertSame('2026-11-01', $designation->effective_date->toDateString());
        $this->assertStringContainsString('Effective date corrected from 24 Nov 2026 to 01 Nov 2026', $designation->remarks);
        $this->assertModelMissing($noOp);

        // Their own move to K came along, and the line before it now ends there.
        $lines = EmployeeReportingHistory::query()
            ->where('employee_id', $this->punit->id)
            ->whereNotIn('change_type', ReportingLineService::NON_REPORTING_CHANGES)
            ->orderBy('effective_date')
            ->get();

        $this->assertSame(['2026-05-15', '2026-11-01'], $lines->map(fn (EmployeeReportingHistory $row): string => $row->effective_date->toDateString())->all());
        $this->assertSame('2026-11-01', $lines->first()->effective_to->toDateString());
        $this->assertNull($lines->last()->effective_to);

        $calculator = new AchievementCalculatorService;
        $this->assertSame(self::TOP_UP, $calculator->getTarget($this->punit->refresh(), Carbon::create(2026, 11, 1)));
        $this->assertSame(2 * self::CALLER_TARGET + self::TOP_UP, $calculator->getTarget($this->teamLeader, Carbon::create(2026, 11, 1)));
    }

    public function test_correcting_a_promotion_moves_the_team_that_stayed_with_them(): void
    {
        $this->service()->change($this->teamLeader, Employee::DESIGNATION_MANAGER, $this->cluster->id, self::SILVER, Carbon::parse(self::TODAY), null, $this->admin);

        $this->service()->redateLastChange($this->teamLeader->refresh(), Carbon::parse('2026-11-01'), $this->admin);

        $teamRows = EmployeeReportingHistory::query()
            ->where('remarks', "Follows {$this->teamLeader->emp_name}'s designation change.")
            ->get();

        $this->assertCount(3, $teamRows);
        $this->assertSame(['2026-11-01'], $teamRows->map(fn (EmployeeReportingHistory $row): string => $row->effective_date->toDateString())->unique()->values()->all());
        $this->assertSame(Employee::DESIGNATION_MANAGER, ReportingTimeline::designationOn($this->teamLeader, Carbon::parse('2026-11-05')));
        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, ReportingTimeline::designationOn($this->teamLeader, Carbon::parse('2026-10-31')));
        $this->assertSame($this->teamLeader->id, ReportingTree::load(Carbon::parse('2026-11-05'))->bossId($this->secondCaller->id));
    }

    public function test_a_correction_is_refused_in_the_future_before_joining_or_past_another_change(): void
    {
        app(ReportingLineService::class)->reportTo($this->punit, $this->otherTeamLeader->id, '2026-11-10', ReportingLineService::CHANGE_TRANSFER);
        $this->promotePunit(self::TODAY);

        foreach ([
            '2026-11-25' => 'future',
            '2026-05-01' => '15 May 2026',
            '2026-11-01' => '10 Nov 2026',
            self::TODAY => 'already takes effect',
        ] as $date => $message) {
            try {
                $this->service()->redateLastChange($this->punit->refresh(), Carbon::parse($date), $this->admin);
                $this->fail("A correction to {$date} was accepted.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($message, $exception->errors()['effective_date'][0]);
            }
        }

        $this->service()->redateLastChange($this->punit->refresh(), Carbon::parse('2026-11-12'), $this->admin);

        $this->assertSame('2026-11-12', $this->designationRow($this->punit)->effective_date->toDateString());
    }

    public function test_the_correction_is_offered_only_once_a_change_is_recorded(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->punit->id])
            ->assertActionHidden('correctChangeDate');

        $this->promotePunit(self::TODAY);

        Livewire::test(EditEmployee::class, ['record' => $this->punit->id])
            ->assertActionVisible('correctChangeDate');
    }

    /*
    |--------------------------------------------------------------------------
    | The effective date is shown wherever the position is
    |--------------------------------------------------------------------------
    */

    public function test_the_view_page_shows_who_they_report_to_and_since_when(): void
    {
        $this->promotePunit('2026-11-01');

        Livewire::test(ViewEmployee::class, ['record' => $this->punit->id])
            ->assertFormSet(['reports_to' => $this->cluster->id])
            ->assertSee('Team Leader since 01 Nov 2026 — promoted from Caller.')
            ->assertActionVisible('changePosition');

        $this->assertSame(
            'Team Leader since 01 Nov 2026 — promoted from Caller.',
            $this->service()->positionSince($this->punit->refresh()),
        );
    }

    public function test_the_view_page_shows_the_boss_of_somebody_never_promoted(): void
    {
        Livewire::test(ViewEmployee::class, ['record' => $this->secondCaller->id])
            ->assertFormSet(['reports_to' => $this->teamLeader->id]);

        $this->assertNull($this->service()->positionSince($this->secondCaller));
    }

    /*
    |--------------------------------------------------------------------------
    | Punit Kumar's recorded promotion (data correction)
    |--------------------------------------------------------------------------
    */

    public function test_the_data_correction_moves_punits_promotion_to_the_first_of_november(): void
    {
        $this->sameBossesRow($this->punit, '2026-11-14');
        $this->promotePunit('2026-11-14');

        $this->runPunitCorrection();

        $this->assertSame('2026-11-01', $this->designationRow($this->punit)->effective_date->toDateString());
        $this->assertSame(self::TOP_UP, (new AchievementCalculatorService)->getTarget($this->punit->refresh(), Carbon::create(2026, 11, 1)));

        // Run again: nothing left to correct.
        $rows = EmployeeReportingHistory::query()->count();
        $this->runPunitCorrection();
        $this->assertSame($rows, EmployeeReportingHistory::query()->count());
    }

    public function test_the_data_correction_dates_a_promotion_that_was_never_recorded(): void
    {
        $this->punit->forceFill([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            ...app(ReportingLineService::class)->columnsUnder($this->cluster->id),
        ])->save();

        $this->runPunitCorrection();

        $this->assertSame('2026-11-01', $this->designationRow($this->punit)->effective_date->toDateString());
        $this->assertSame($this->teamLeader->id, ReportingTree::load(Carbon::parse('2026-10-31'))->bossId($this->punit->id));
        $this->assertSame($this->cluster->id, ReportingTree::load(Carbon::parse('2026-11-01'))->bossId($this->punit->id));
        $this->assertSame(self::CALLER_TARGET, (new AchievementCalculatorService)->getTarget($this->punit->refresh(), Carbon::create(2026, 10, 1)));
    }

    private function runPunitCorrection(): void
    {
        (require database_path('migrations/2026_11_24_113213_correct_punit_kumar_promotion_effective_date.php'))->up();
    }

    private function promotePunit(string $effectiveDate): void
    {
        $this->service()->change(
            $this->punit->refresh(),
            Employee::DESIGNATION_TEAM_LEADER,
            $this->cluster->id,
            self::SILVER,
            Carbon::parse($effectiveDate),
            null,
            $this->admin,
        );

        $this->punit->refresh();
    }

    /**
     * A row like "Follows Manoj Sajwan's designation change." that kept
     * them under the very same people.
     */
    private function sameBossesRow(Employee $employee, string $effectiveDate): EmployeeReportingHistory
    {
        $columns = [];

        foreach (array_keys(Employee::REPORTING_COLUMNS) as $column) {
            $columns['old_'.$column] = $employee->{$column};
            $columns['new_'.$column] = $employee->{$column};
        }

        return EmployeeReportingHistory::query()->create([
            'employee_id' => $employee->id,
            ...$columns,
            'effective_date' => $effectiveDate,
            'change_type' => ReportingLineService::CHANGE_REPORTING,
            'remarks' => "Follows {$this->cluster->emp_name}'s designation change.",
        ]);
    }

    private function designationRow(Employee $employee): EmployeeReportingHistory
    {
        return EmployeeReportingHistory::query()
            ->where('employee_id', $employee->id)
            ->where('change_type', ReportingLineService::CHANGE_DESIGNATION)
            ->sole();
    }

    private function service(): EmployeePositionChangeService
    {
        return app(EmployeePositionChangeService::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(int $designation, ?Employee $boss = null, array $attributes = []): Employee
    {
        $position = Designation::query()->firstOrCreate(['name' => 'Executive']);

        return Employee::factory()->create([
            'designation' => $designation,
            'position' => $position->name,
            'category' => self::SILVER,
            'doj' => '2025-01-01',
            'reporting_date' => '2025-01-01',
            'exit_status' => 'no',
            'exit_date' => null,
            ...app(ReportingLineService::class)->columnsUnder($boss?->id),
            ...$attributes,
        ]);
    }
}
