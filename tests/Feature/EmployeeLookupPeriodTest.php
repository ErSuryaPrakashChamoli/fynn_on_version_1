<?php

namespace Tests\Feature;

use App\Enums\EmployeeLookupSection;
use App\Filament\Pages\EmployeeLookup;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\Performance\EmployeeFigureBreakdownService;
use App\Services\ReportingLineService;
use App\Support\EmployeeLookupPeriod;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Employee Lookup page analyses any period picked at its top — a
 * month, a financial year, all time or a custom range — and by default
 * shows only people on the rolls in it, with "Include inactive" to see
 * everyone and a "Moved" flag for anyone who changed team during it.
 */
class EmployeeLookupPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Employee $teamLeader;

    private Employee $otherTeamLeader;

    private Employee $activeCaller;

    private Employee $exitedCaller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 11, 14, 11));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Role::findOrCreate('Admin');

        $manager = $this->employee('Meera Manager', Employee::DESIGNATION_MANAGER);
        $this->teamLeader = $this->employee('Tarun Leader', Employee::DESIGNATION_TEAM_LEADER, ['manager_id' => $manager->id]);
        $this->otherTeamLeader = $this->employee('Other Leader', Employee::DESIGNATION_TEAM_LEADER, ['manager_id' => $manager->id]);
        $this->activeCaller = $this->employee('Asha Active', Employee::DESIGNATION_CALLER, ['superviser_id' => $this->teamLeader->id, 'manager_id' => $manager->id]);
        $this->exitedCaller = $this->employee('Eshan Exited', Employee::DESIGNATION_CALLER, [
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $manager->id,
            'exit_status' => 'yes',
            'exit_date' => '2026-08-20',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(string $name, int $designation, array $attributes = []): Employee
    {
        return Employee::factory()->create([
            'emp_name' => $name,
            'designation' => $designation,
            'exit_status' => 'no',
            'exit_date' => null,
            'doj' => '2026-01-01',
            'reporting_date' => '2026-01-01',
            ...$attributes,
        ]);
    }

    private function loginAsAdmin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
    }

    private function breakdowns(): EmployeeFigureBreakdownService
    {
        return app(EmployeeFigureBreakdownService::class);
    }

    public function test_the_period_follows_the_topbar_until_one_is_picked(): void
    {
        request()->cookies->set('selected_month', '2026-09');

        $this->assertSame('September 2026', EmployeeLookupPeriod::fromInput(null)->label);
        $this->assertSame('October 2026', EmployeeLookupPeriod::fromInput('month', '2026-10')->label);
        $this->assertSame('September 2026', EmployeeLookupPeriod::fromInput('month', 'garbage')->label, 'A bad month falls back to the topbar.');

        $financialYear = EmployeeLookupPeriod::fromInput('fy', financialYear: '2026');
        $this->assertSame('2026-04-01', $financialYear->start->toDateString());
        $this->assertSame('2026-11-30', $financialYear->end->toDateString(), 'A financial year in progress stops at the end of this month.');
        $this->assertCount(8, $financialYear->months());
        $this->assertFalse($financialYear->isSingleMonth());

        $pastYear = EmployeeLookupPeriod::fromInput('fy', financialYear: '2024');
        $this->assertSame('2025-03-31', $pastYear->end->toDateString());
        $this->assertCount(12, $pastYear->months());

        $custom = EmployeeLookupPeriod::fromInput('custom', from: '2026-10-20', to: '2026-09-10');
        $this->assertSame('2026-09-10', $custom->start->toDateString(), 'A reversed range is put the right way round.');
        $this->assertTrue($custom->hasPartialMonths());
        $this->assertCount(2, $custom->months());

        $this->assertSame(['period' => 'custom', 'from' => '2026-09-10', 'to' => '2026-10-20'], $custom->toQuery());
    }

    public function test_someone_is_active_when_on_the_rolls_on_any_day_of_the_period(): void
    {
        $august = EmployeeLookupPeriod::forMonth(Carbon::create(2026, 8, 1));
        $september = EmployeeLookupPeriod::forMonth(Carbon::create(2026, 9, 1));
        $financialYear = EmployeeLookupPeriod::forFinancialYear(2026);

        $this->assertTrue($august->wasActive($this->exitedCaller), 'Left on 20 Aug: still on the rolls in August.');
        $this->assertFalse($september->wasActive($this->exitedCaller));
        $this->assertTrue($financialYear->wasActive($this->exitedCaller));

        $joiner = $this->employee('Late Joiner', Employee::DESIGNATION_CALLER, ['reporting_date' => '2026-10-05', 'doj' => '2026-10-05']);
        $this->assertFalse($september->wasActive($joiner));
        $this->assertTrue(EmployeeLookupPeriod::forMonth(Carbon::create(2026, 10, 1))->wasActive($joiner));

        $this->assertNotContains($this->exitedCaller->id, $september->activeEmployeeIds());
        $this->assertContains($this->activeCaller->id, $september->activeEmployeeIds());
    }

    public function test_a_multi_month_target_adds_up_each_months_target(): void
    {
        $period = EmployeeLookupPeriod::fromInput('custom', from: '2026-08-01', to: '2026-10-31');
        $calculator = app(AchievementCalculatorService::class);

        $target = $this->breakdowns()->target($this->teamLeader, $period);

        $this->assertCount(3, $target['months']);
        $expected = array_sum(array_map(fn (Carbon $month): float => $calculator->getTarget($this->teamLeader, $month), $period->months()));
        $this->assertEqualsWithDelta($expected, $target['total'], 0.01);
        $this->assertEqualsWithDelta($target['total'], $target['callers_total'] + $target['top_up'], 0.01);

        $summary = $this->breakdowns()->summary($this->teamLeader, $period);
        $this->assertEqualsWithDelta($expected, $summary['target'], 0.01);
    }

    public function test_a_caller_who_left_before_the_period_is_not_listed_and_adds_nothing(): void
    {
        $target = $this->breakdowns()->target($this->teamLeader, EmployeeLookupPeriod::forMonth(Carbon::create(2026, 10, 1)));
        $callers = collect($target['callers'])->keyBy('name');

        $this->assertTrue($callers['Asha Active']['active']);
        $this->assertArrayNotHasKey('Eshan Exited', $callers->all(), 'Left on 20 Aug: not one of October\'s callers.');
        $this->assertEqualsWithDelta($target['callers_total'], $callers->sum('share'), 0.01, 'Leaving them out changes no total.');

        $august = collect($this->breakdowns()->target($this->teamLeader, EmployeeLookupPeriod::forMonth(Carbon::create(2026, 8, 1)))['callers'])->keyBy('name');
        $this->assertTrue($august['Eshan Exited']['active'], 'Still listed in the month they left.');
        $this->assertSame('2026-08-20', $august['Eshan Exited']['left_during_period']->toDateString());
    }

    public function test_a_caller_moved_into_the_team_mid_month_is_marked_moved_with_the_dates(): void
    {
        app(ReportingLineService::class)->reportTo(
            $this->activeCaller,
            $this->otherTeamLeader->id,
            '2026-10-26',
            ReportingLineService::CHANGE_TRANSFER,
        );

        $october = EmployeeLookupPeriod::forMonth(Carbon::create(2026, 10, 1));

        $incoming = collect($this->breakdowns()->target($this->otherTeamLeader, $october)['callers'])->firstWhere('name', 'Asha Active');
        $outgoing = collect($this->breakdowns()->target($this->teamLeader, $october)['callers'])->firstWhere('name', 'Asha Active');

        $this->assertNotNull($incoming, 'A caller moved into the team shows up in it.');
        $this->assertTrue($incoming['moved']);
        $this->assertStringContainsString('26 Oct', (string) $incoming['note']);
        $this->assertTrue($outgoing['moved'], 'And is flagged on the team they left.');
    }

    public function test_a_moved_caller_carries_the_day_they_moved_in_or_out_and_their_joining_date(): void
    {
        app(ReportingLineService::class)->reportTo(
            $this->activeCaller,
            $this->otherTeamLeader->id,
            '2026-10-26',
            ReportingLineService::CHANGE_TRANSFER,
        );

        $october = EmployeeLookupPeriod::forMonth(Carbon::create(2026, 10, 1));

        $incoming = collect($this->breakdowns()->target($this->otherTeamLeader, $october)['callers'])->firstWhere('name', 'Asha Active');
        $outgoing = collect($this->breakdowns()->target($this->teamLeader, $october)['callers'])->firstWhere('name', 'Asha Active');

        $this->assertSame(['in'], array_column($incoming['movements'], 'direction'));
        $this->assertSame('2026-10-26', $incoming['movements'][0]['on']->toDateString());
        $this->assertSame(6, $incoming['days_here'], '26 – 31 Oct in the new team.');

        $this->assertSame(['out'], array_column($outgoing['movements'], 'direction'));
        $this->assertSame('2026-10-25', $outgoing['movements'][0]['on']->toDateString(), 'Moved out on their last day in the team.');
        $this->assertSame(25, $outgoing['days_here']);

        $this->assertSame('2026-01-01', $incoming['joined_on']->toDateString());
        $this->assertNull($incoming['left_on']);

        $this->employee('Lata Leaver', Employee::DESIGNATION_CALLER, ['superviser_id' => $this->teamLeader->id, 'exit_status' => 'yes', 'exit_date' => '2026-10-07']);
        $this->employee('Neel Joiner', Employee::DESIGNATION_CALLER, ['superviser_id' => $this->teamLeader->id, 'doj' => '2026-10-12', 'reporting_date' => '2026-10-12']);
        $callers = collect($this->breakdowns()->target($this->teamLeader, $october)['callers'])->keyBy('name');

        $this->assertSame('2026-10-07', $callers['Lata Leaver']['left_on']->toDateString());
        $this->assertSame('2026-10-07', $callers['Lata Leaver']['left_during_period']->toDateString());
        $this->assertNull($callers['Lata Leaver']['joined_during_period']);
        $this->assertSame([], $callers['Lata Leaver']['movements'], 'Leaving is not a move out of the team.');
        $this->assertSame(7, $callers['Lata Leaver']['days_here']);

        $this->assertSame('2026-10-12', $callers['Neel Joiner']['joined_on']->toDateString());
        $this->assertSame('2026-10-12', $callers['Neel Joiner']['joined_during_period']->toDateString());
        $this->assertNull($callers['Neel Joiner']['left_during_period']);
        $this->assertSame([], $callers['Neel Joiner']['movements'], 'Joining is not a move into the team.');
    }

    public function test_a_caller_in_the_team_all_period_has_no_movements_and_every_day_here(): void
    {
        $caller = collect($this->breakdowns()->target($this->teamLeader, EmployeeLookupPeriod::forMonth(Carbon::create(2026, 10, 1)))['callers'])
            ->firstWhere('name', 'Asha Active');

        $this->assertFalse($caller['moved']);
        $this->assertSame([], $caller['movements']);
        $this->assertSame(31, $caller['days_here']);
    }

    public function test_the_target_pop_up_shows_joining_dates_and_moves_in_and_out(): void
    {
        $this->loginAsAdmin();

        app(ReportingLineService::class)->reportTo(
            $this->activeCaller,
            $this->otherTeamLeader->id,
            '2026-10-26',
            ReportingLineService::CHANGE_TRANSFER,
        );

        $october = ['section' => EmployeeLookupSection::Achievement->value, 'period' => 'month', 'month' => '2026-10'];

        Livewire::withQueryParams(['employee' => $this->otherTeamLeader->id, ...$october])
            ->test(EmployeeLookup::class)
            ->mountAction('explain', ['figure' => 'target'])
            ->assertMountedActionModalSee(['DOJ / Exit', '01 Jan 2026', 'Moved in · 26 Oct', '6 of 31 days']);

        Livewire::withQueryParams(['employee' => $this->teamLeader->id, ...$october])
            ->test(EmployeeLookup::class)
            ->mountAction('explain', ['figure' => 'target'])
            ->assertMountedActionModalSee('Moved out · 25 Oct')
            ->assertMountedActionModalDontSee(['Moved in ·', 'Eshan Exited']);
    }

    public function test_the_target_pop_up_marks_new_joiners_and_leavers_with_their_dates(): void
    {
        $this->loginAsAdmin();

        $this->employee('Lata Leaver', Employee::DESIGNATION_CALLER, ['superviser_id' => $this->teamLeader->id, 'exit_status' => 'yes', 'exit_date' => '2026-10-07']);
        $this->employee('Neel Joiner', Employee::DESIGNATION_CALLER, ['superviser_id' => $this->teamLeader->id, 'doj' => '2026-10-12', 'reporting_date' => '2026-10-12']);

        Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => EmployeeLookupSection::Achievement->value, 'period' => 'month', 'month' => '2026-10'])
            ->test(EmployeeLookup::class)
            ->mountAction('explain', ['figure' => 'target'])
            ->assertMountedActionModalSee(['New joiner · 12 Oct', 'Left · 07 Oct', 'DOJ / Exit', '12 Oct 2026', '07 Oct 2026']);
    }

    public function test_the_target_pop_up_counts_active_inactive_and_counted_callers_and_switches_between_them(): void
    {
        $this->loginAsAdmin();

        // Left 07 Oct: under the 10-day minimum, so not counted, and inactive.
        $this->employee('Lata Leaver', Employee::DESIGNATION_CALLER, ['superviser_id' => $this->teamLeader->id, 'exit_status' => 'yes', 'exit_date' => '2026-10-07']);

        $october = EmployeeLookupPeriod::forMonth(Carbon::create(2026, 10, 1));
        $group = $this->breakdowns()->target($this->teamLeader, $october)['caller_groups'][0];

        $this->assertSame(['Asha Active', 'Lata Leaver'], array_column($group['callers'], 'name'), 'Eshan left in August: not one of October\'s callers.');
        $this->assertSame(1, $group['on_rolls'], 'Active: still on the rolls at the end of October.');
        $this->assertSame(1, $group['left'], 'Inactive: left during October.');
        $this->assertSame(1, $group['counted']);

        Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => EmployeeLookupSection::Achievement->value, 'period' => 'month', 'month' => '2026-10'])
            ->test(EmployeeLookup::class)
            ->mountAction('explain', ['figure' => 'target'])
            ->assertMountedActionModalSee(['Callers in October 2026', 'Including not counted', 'Counted (1)', 'Including not counted (2)', '1 not-counted caller hidden'])
            ->assertMountedActionModalSeeHtml('x-show="showNotCounted"')
            ->assertMountedActionModalDontSee(['Active only', 'Include inactive']);
    }

    /**
     * The days bar fills only the days worked (6 of 31 → 19.35%) and draws
     * no 10-day tick across it.
     */
    public function test_the_days_bar_fills_the_days_worked_with_no_tick_across_it(): void
    {
        $this->loginAsAdmin();

        app(ReportingLineService::class)->reportTo(
            $this->activeCaller,
            $this->otherTeamLeader->id,
            '2026-10-26',
            ReportingLineService::CHANGE_TRANSFER,
        );

        Livewire::withQueryParams([
            'employee' => $this->otherTeamLeader->id,
            'section' => EmployeeLookupSection::Achievement->value,
            'period' => 'month',
            'month' => '2026-10',
        ])
            ->test(EmployeeLookup::class)
            ->mountAction('explain', ['figure' => 'target'])
            ->assertMountedActionModalSee('6 of 31 days')
            ->assertMountedActionModalSeeHtml('<span class="fynn-days-fill" style="width: 19.35%"></span>')
            ->assertMountedActionModalDontSeeHtml(['fynn-days-mark', '10-day minimum', 'is-off']);
    }

    public function test_lists_hide_inactive_owners_until_asked_to_include_them(): void
    {
        $this->loginAsAdmin();

        $activeCase = Customer::factory()->create(['employee_id' => $this->activeCaller->id, 'assign_to' => $this->activeCaller->id, 'created_at' => '2026-11-05']);
        // Recorded after the exit date: an owner not on the rolls in November.
        $inactiveCase = Customer::factory()->create(['employee_id' => $this->exitedCaller->id, 'assign_to' => $this->exitedCaller->id, 'created_at' => '2026-11-06']);

        $query = ['employee' => $this->teamLeader->id, 'section' => EmployeeLookupSection::Customers->value, 'period' => 'month', 'month' => '2026-11'];

        Livewire::withQueryParams($query)
            ->test(EmployeeLookup::class)
            ->assertCanSeeTableRecords([$activeCase])
            ->assertCanNotSeeTableRecords([$inactiveCase])
            ->assertSee('Active employees only');

        Livewire::withQueryParams([...$query, 'inactive' => '1'])
            ->test(EmployeeLookup::class)
            ->assertCanSeeTableRecords([$activeCase, $inactiveCase]);
    }

    public function test_the_period_and_employee_controls_reload_the_page_with_the_choice(): void
    {
        $this->loginAsAdmin();

        $page = fn () => Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => EmployeeLookupSection::Achievement->value])
            ->test(EmployeeLookup::class);

        $page()->set('data.period_mode', 'all')
            ->assertRedirect(EmployeeLookup::getUrl(['employee' => $this->teamLeader->id, 'section' => 'achievement', 'period' => 'all']));

        $page()->set('data.period_mode', 'fy')
            ->assertRedirect(EmployeeLookup::getUrl(['employee' => $this->teamLeader->id, 'section' => 'achievement', 'period' => 'fy', 'fy' => '2026']));

        $page()->set('data.employees', 'all')
            ->assertRedirect(EmployeeLookup::getUrl(['employee' => $this->teamLeader->id, 'section' => 'achievement', 'period' => 'month', 'month' => '2026-11', 'inactive' => '1']));

        // A custom range waits for both dates.
        $page()->set('data.period_mode', 'custom')
            ->set('data.period_from', null)
            ->assertNoRedirect();
    }

    public function test_links_between_views_keep_the_period_and_the_inactive_choice(): void
    {
        $this->loginAsAdmin();

        Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => 'profile', 'period' => 'fy', 'fy' => '2026', 'inactive' => '1'])
            ->test(EmployeeLookup::class)
            ->assertSee('FY 2026-27')
            ->assertSee('Targets and incentives add up the 8 months in it')
            ->assertDontSee('@if')
            ->assertDontSee('@endif')
            ->assertSeeHtml(e(EmployeeLookup::getUrl(['employee' => $this->teamLeader->id, 'section' => 'achievement', 'period' => 'fy', 'fy' => '2026', 'inactive' => '1'])));
    }

    public function test_the_pop_up_marks_inactive_people_and_offers_the_switch(): void
    {
        $this->loginAsAdmin();

        Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => 'achievement', 'period' => 'month', 'month' => '2026-10'])
            ->test(EmployeeLookup::class)
            ->mountAction('explain', ['figure' => 'net'])
            ->assertMountedActionModalSee('Include inactive (1)')
            ->assertMountedActionModalSee('Not active in period')
            ->assertMountedActionModalSeeHtml('x-show="showInactive"');
    }

    public function test_a_longer_period_explains_the_incentive_month_by_month(): void
    {
        Customer::factory()->create([
            'employee_id' => $this->activeCaller->id,
            'assign_to' => $this->activeCaller->id,
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => 3000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'disbursal_date' => '2026-10-10',
        ]);

        $period = EmployeeLookupPeriod::fromInput('custom', from: '2026-09-01', to: '2026-10-31');
        $incentive = $this->breakdowns()->incentive($this->activeCaller, $period);

        $this->assertSame('months', $incentive['kind']);
        $this->assertSame([0.0, 5500.0], array_column($incentive['months'], 'incentive'));
        $this->assertSame(5500.0, $incentive['incentive']);
        $this->assertSame(5500.0, $this->breakdowns()->summary($this->activeCaller, $period)['incentive']);
    }
}
