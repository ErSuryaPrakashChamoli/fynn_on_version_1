<?php

namespace Tests\Feature;

use App\Enums\EmployeeLookupSection;
use App\Filament\Pages\EmployeeLookup;
use App\Models\Customer;
use App\Models\CustomerStageHistory;
use App\Models\Employee;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\Performance\EmployeeFigureBreakdownService;
use App\Services\Performance\EmployeePerformanceMetricsService;
use App\Support\EmployeeLookupPeriod;
use App\Support\SelectedMonth;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Employee Lookup "how is this calculated?" pop-ups and the lists
 * behind the OTP & Funnel cards: every explanation must land on the very
 * figure the card shows, and every list must hold exactly what its card
 * counts.
 */
class EmployeeFigureBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $teamLeader;

    /** @var list<Employee> */
    private array $callers = [];

    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 11, 14, 11));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Role::findOrCreate('Admin');

        $this->month = now()->startOfMonth();

        $this->manager = $this->employee('Meera Manager', Employee::DESIGNATION_MANAGER);
        $this->teamLeader = $this->employee('Tarun Leader', Employee::DESIGNATION_TEAM_LEADER, ['manager_id' => $this->manager->id]);

        foreach (['Asha', 'Bina', 'Chetan'] as $name) {
            $this->callers[] = $this->employee($name, Employee::DESIGNATION_CALLER, [
                'superviser_id' => $this->teamLeader->id,
                'manager_id' => $this->manager->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(string $name, int $designation, array $attributes = []): Employee
    {
        return Employee::factory()->create([
            'emp_name' => $name,
            'designation' => $designation,
            'doj' => '2025-01-01',
            'reporting_date' => '2025-01-01',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function disburse(Employee $owner, float $amount, array $attributes = []): Customer
    {
        return Customer::factory()->create([
            'employee_id' => $owner->id,
            'assign_to' => $owner->id,
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => $amount,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'disbursal_date' => $this->month->copy()->addDays(2),
            'created_at' => $this->month->copy()->addDay(),
            ...$attributes,
        ]);
    }

    private function period(): EmployeeLookupPeriod
    {
        return EmployeeLookupPeriod::forMonth($this->month);
    }

    private function breakdowns(): EmployeeFigureBreakdownService
    {
        return app(EmployeeFigureBreakdownService::class);
    }

    private function loginAsAdmin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
    }

    public function test_a_callers_target_is_their_category_amount(): void
    {
        $target = $this->breakdowns()->target($this->callers[0], $this->period());

        $this->assertSame('caller', $target['kind']);
        $this->assertSame(app(AchievementCalculatorService::class)->getTarget($this->callers[0], $this->month), $target['total']);
    }

    public function test_a_team_target_is_its_callers_shares_plus_the_understaffed_top_ups(): void
    {
        // A second Team Leader with a single caller: understaffed.
        $smallTeamLeader = $this->employee('Small Leader', Employee::DESIGNATION_TEAM_LEADER, ['manager_id' => $this->manager->id]);
        $this->employee('Dev', Employee::DESIGNATION_CALLER, ['superviser_id' => $smallTeamLeader->id, 'manager_id' => $this->manager->id]);

        $target = $this->breakdowns()->target($this->manager, $this->period());

        $this->assertSame('team', $target['kind']);
        $this->assertCount(4, $target['callers']);
        $this->assertEqualsWithDelta($target['total'], $target['callers_total'] + $target['top_up'], 0.01);
        $this->assertEqualsWithDelta(3000000.0, $target['top_up'], 0.01);

        $understaffed = collect($target['team_leaders'])->where('months_understaffed', '>', 0)->pluck('name')->all();
        $this->assertSame(['Small Leader'], $understaffed);
    }

    public function test_every_disbursed_case_is_listed_and_the_rows_add_up_to_the_totals(): void
    {
        $this->disburse($this->callers[0], 1000000, ['sanctioned_bank' => 'BFL Prime', 'cashback' => 1000, 'subvention' => 100, 'docking' => '10']);
        $this->disburse($this->callers[1], 2000000, ['cashback' => 2000, 'subvention' => 200, 'docking' => '20']);
        $this->disburse($this->callers[2], 500000);

        $cases = $this->breakdowns()->achievement($this->teamLeader, $this->period());
        $rows = collect($cases['rows']);

        $this->assertSame(3, $cases['count']);
        $this->assertEqualsWithDelta($cases['totals']['actual'], $rows->sum('actual'), 0.01);
        $this->assertEqualsWithDelta($cases['totals']['count_achievement'], $rows->sum('net'), 0.01);

        $this->assertSame(50, $rows->firstWhere('bank', 'BFL Prime')['deduction_rate']);
        $this->assertSame(100, $rows->firstWhere('actual', 2000000.0)['deduction_rate']);
        $this->assertNull($rows->firstWhere('actual', 500000.0)['deduction_rate'], 'No deductions, no rate to show.');
        $this->assertEqualsWithDelta(1000000 - (1110 * 50), $rows->firstWhere('bank', 'BFL Prime')['net'], 0.01);

        $performance = app(AchievementCalculatorService::class)->getPerformance($this->teamLeader, $this->month);
        $this->assertEqualsWithDelta($performance['count_achievement'], $cases['totals']['count_achievement'], 0.01);
    }

    public function test_the_team_leader_steps_land_on_the_services_own_incentive(): void
    {
        foreach ($this->callers as $caller) {
            $this->disburse($caller, 3000000);
        }

        $incentive = $this->breakdowns()->incentive($this->teamLeader, $this->period());

        $this->assertSame('team_leader', $incentive['kind']);
        $this->assertTrue($incentive['meets_gate']);
        $this->assertGreaterThan(0, $incentive['incentive']);
        $this->assertSame($incentive['incentive'], $incentive['steps']['result']);
    }

    public function test_a_manager_is_explained_by_ppp_and_a_caller_by_slabs(): void
    {
        $this->disburse($this->callers[0], 3200000);

        $manager = $this->breakdowns()->incentive($this->manager, $this->period());
        $this->assertSame('manager', $manager['kind']);
        $this->assertSame(
            app(AchievementCalculatorService::class)->getManagerIncentiveBreakdown($this->manager, $this->month)['incentive'],
            $manager['incentive'],
        );
        $this->assertCount(5, $manager['bands']);

        $caller = $this->breakdowns()->incentive($this->callers[0], $this->period());
        $this->assertSame('slab', $caller['kind']);
        $this->assertSame(3000000.0, $caller['reached']);
        $this->assertSame(5500.0, (float) $caller['incentive']);
    }

    public function test_each_card_opens_its_explanation(): void
    {
        $this->loginAsAdmin();
        $this->disburse($this->callers[0], 1000000, ['application_no' => 'APP-777']);

        $expectations = [
            'target' => 'How the target is calculated',
            'net' => 'APP-777',
            'gross' => 'How gross achievement is added up',
            'cashback' => 'How the deductions are worked out',
            'percentage' => 'Achievement % = Net achievement',
            'remaining' => 'Remaining to target = Target',
            'category' => 'Target category',
            'incentive' => 'How the incentive is calculated',
        ];

        foreach ($expectations as $figure => $text) {
            Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => EmployeeLookupSection::Achievement->value])
                ->test(EmployeeLookup::class)
                ->mountAction('explain', ['figure' => $figure])
                ->assertActionMounted('explain')
                ->assertMountedActionModalSee($text);
        }
    }

    public function test_the_achievement_cards_are_buttons_that_open_the_explanation(): void
    {
        $this->loginAsAdmin();

        Livewire::withQueryParams(['employee' => $this->callers[0]->id, 'section' => EmployeeLookupSection::Achievement->value])
            ->test(EmployeeLookup::class)
            ->assertSeeHtml("mountAction('explain', { figure: 'target' })")
            ->assertSeeHtml("mountAction('explain', { figure: 'incentive' })")
            ->assertSee('How is this calculated?');
    }

    /**
     * Filament only draws the action-modal container of a page with a table
     * inside the table itself. The figure views draw no table, so without
     * their own container the browser has nowhere to put the pop-up the
     * server sends back and a click on a card does nothing.
     */
    public function test_every_view_carries_the_container_the_pop_up_opens_in(): void
    {
        $this->loginAsAdmin();

        foreach (EmployeeLookupSection::cases() as $section) {
            $html = Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => $section->value])
                ->test(EmployeeLookup::class)
                ->html();

            $this->assertSame(1, substr_count($html, 'wire:partial="action-modals"'), "{$section->value} must carry exactly one action-modal container.");
        }
    }

    public function test_the_funnel_cards_link_to_their_lists(): void
    {
        $this->loginAsAdmin();

        $page = Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => EmployeeLookupSection::Performance->value])
            ->test(EmployeeLookup::class);

        foreach ([EmployeeLookupSection::EligibleOtp, EmployeeLookupSection::NotEligibleOtp, EmployeeLookupSection::CaseLogins, EmployeeLookupSection::Disbursals, EmployeeLookupSection::Logins] as $section) {
            $page->assertSeeHtml(e(EmployeeLookup::getUrl(['employee' => $this->teamLeader->id, 'section' => $section->value])));
        }
    }

    public function test_each_funnel_list_holds_exactly_what_its_card_counts(): void
    {
        $this->loginAsAdmin();
        request()->cookies->set('selected_month', $this->month->format('Y-m'));

        $mover = User::factory()->create(['employee_id' => $this->callers[0]->id]);
        $outsiderUser = User::factory()->create(['employee_id' => $this->employee('Outsider', Employee::DESIGNATION_CALLER)->id]);

        $eligible = $this->disburse($this->callers[0], 100000, ['eligibility_status' => 'eligible']);
        $notEligible = $this->disburse($this->callers[1], 100000, ['eligibility_status' => 'not_eligible']);

        $moved = CustomerStageHistory::query()->create([
            'customer_id' => $eligible->id,
            'stage_name' => 'underwriting',
            'status_value' => 'Moved to Underwriting',
            'user_id' => $mover->id,
        ]);
        $moved->forceFill(['created_at' => $this->month->copy()->addDays(3)])->save();

        $foreign = CustomerStageHistory::query()->create([
            'customer_id' => $notEligible->id,
            'stage_name' => 'underwriting',
            'status_value' => 'Moved to Underwriting',
            'user_id' => $outsiderUser->id,
        ]);
        $foreign->forceFill(['created_at' => $this->month->copy()->addDays(3)])->save();

        [$start, $end] = SelectedMonth::range();
        $metrics = app(EmployeePerformanceMetricsService::class)->rawMetrics($this->teamLeader, $start, $end);

        $list = fn (EmployeeLookupSection $section) => Livewire::withQueryParams(['employee' => $this->teamLeader->id, 'section' => $section->value])
            ->test(EmployeeLookup::class);

        $list(EmployeeLookupSection::EligibleOtp)
            ->assertCanSeeTableRecords([$eligible])
            ->assertCanNotSeeTableRecords([$notEligible])
            ->assertCountTableRecords($metrics['eligible_otp_count']);

        $list(EmployeeLookupSection::Customers)
            ->assertCountTableRecords($metrics['otp_count']);

        $list(EmployeeLookupSection::CaseLogins)
            ->assertCanSeeTableRecords([$moved])
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertCountTableRecords($metrics['login_count'])
            ->assertSee($eligible->customer_name);
    }
}
