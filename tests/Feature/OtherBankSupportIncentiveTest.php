<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OtherBankIncentiveSlab;
use App\Models\OtherBankSupportTarget;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\OtherBankSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The other-bank slice of the month's business, the Admin-set target and the
 * Admin-defined slab ladder that turns it into each support user's incentive.
 */
class OtherBankSupportIncentiveTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $august;

    private OtherBankSupportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => OtherBankSupportService::ROLE]);

        $this->august = Carbon::parse('2026-08-01');
        $this->service = app(OtherBankSupportService::class);
    }

    public function test_monthly_summary_shows_other_bank_business_as_a_share_of_the_unchanged_lms_total(): void
    {
        $this->disbursed('HDFC Bank', 1000000);
        $this->disbursed('Tata Capital', 500000);
        $this->disbursed('BFL Prime', 2000000);
        $this->disbursed('HDFC Bank', 900000, '2026-07-20');

        $summary = $this->service->monthlySummary($this->august);

        $this->assertEquals(3500000, $summary['total_achievement']);
        $this->assertEquals(1500000, $summary['other_bank_achievement']);
        $this->assertSame(3, $summary['total_files']);
        $this->assertSame(2, $summary['other_bank_files']);
        $this->assertEquals(42.86, $summary['share_percentage']);

        // The LMS company-wide count achievement is exactly what it was.
        $this->assertEquals(
            3500000,
            app(AchievementCalculatorService::class)->getPerformance(null, $this->august)['count_achievement'],
        );
    }

    public function test_other_bank_achievement_uses_the_lms_net_formula(): void
    {
        $this->disbursed('Axis Bank', 1000000, cashback: 2000, subvention: 1000);

        $summary = $this->service->monthlySummary($this->august);

        // AchievementCalculatorService weighs deductions x100 (x50 for BFL
        // Prime/Growth): 10,00,000 - (2,000 + 1,000) x 100.
        $this->assertEquals(1000000, $summary['other_bank_disbursed']);
        $this->assertEquals(700000, $summary['other_bank_achievement']);
    }

    public function test_every_support_user_is_credited_the_whole_team_achievement_against_their_own_target(): void
    {
        $this->disbursed('ICICI Bank', 1500000);

        $first = $this->supportUser();
        $second = $this->supportUser();

        OtherBankSupportTarget::factory()->create(['user_id' => $first->id, 'month' => '2026-08-01', 'target_amount' => 1000000]);
        OtherBankSupportTarget::factory()->create(['user_id' => $second->id, 'month' => '2026-08-01', 'target_amount' => 3000000]);

        $this->assertEquals(1500000, $this->service->performanceFor($first, $this->august)['achievement']);
        $this->assertEquals(150.0, $this->service->performanceFor($first, $this->august)['percentage']);
        $this->assertEquals(50.0, $this->service->performanceFor($second, $this->august)['percentage']);
    }

    public function test_target_is_zero_until_the_admin_sets_it_for_that_month(): void
    {
        $user = $this->supportUser();

        OtherBankSupportTarget::factory()->create(['user_id' => $user->id, 'month' => '2026-09-01', 'target_amount' => 2000000]);

        $this->assertEquals(0.0, $this->service->targetFor($user, $this->august));
        $this->assertEquals(2000000.0, $this->service->targetFor($user, Carbon::parse('2026-09-01')));
        $this->assertEquals(0.0, $this->service->performanceFor($user, $this->august)['percentage']);
    }

    public function test_highest_reached_fixed_slab_is_paid_and_next_slab_is_reported(): void
    {
        $this->slab('2026-08-01', 1000000, 5000);
        $this->slab('2026-08-01', 1500000, 9000);
        $this->slab('2026-08-01', 2000000, 15000);

        $result = $this->service->incentiveFor(1700000, $this->august);

        $this->assertEquals(9000, $result['incentive']);
        $this->assertEquals(1500000, $result['slab']->min_achievement);
        $this->assertEquals(2000000, $result['next_slab']->min_achievement);
        $this->assertEquals(300000, $result['remaining_to_next']);
    }

    public function test_exactly_reaching_a_slab_minimum_earns_that_slab(): void
    {
        $this->slab('2026-08-01', 1000000, 5000);

        $this->assertEquals(5000, $this->service->incentiveFor(1000000, $this->august)['incentive']);
        $this->assertEquals(0, $this->service->incentiveFor(999999, $this->august)['incentive']);
    }

    public function test_percentage_slab_pays_a_share_of_the_achievement(): void
    {
        OtherBankIncentiveSlab::factory()->percentage(1.5)->create([
            'effective_month' => '2026-08-01',
            'min_achievement' => 1000000,
        ]);

        $this->assertEquals(22500, $this->service->incentiveFor(1500000, $this->august)['incentive']);
    }

    public function test_slab_ladder_applies_until_a_later_month_defines_a_new_one(): void
    {
        $this->slab('2026-07-01', 1000000, 1000);
        $this->slab('2026-09-01', 1000000, 2000);

        $this->assertEquals(1000, $this->service->incentiveFor(1200000, $this->august)['incentive']);
        $this->assertEquals(2000, $this->service->incentiveFor(1200000, Carbon::parse('2026-09-01'))['incentive']);
        $this->assertEquals(0, $this->service->incentiveFor(1200000, Carbon::parse('2026-06-01'))['incentive']);
        $this->assertTrue($this->service->slabsFor(Carbon::parse('2026-06-01'))->isEmpty());
    }

    public function test_incentive_flows_into_each_support_users_performance(): void
    {
        $this->disbursed('Yes Bank', 2500000);
        $this->slab('2026-08-01', 2000000, 12000);

        $this->assertEquals(12000, $this->service->performanceFor($this->supportUser(), $this->august)['incentive']);
    }

    public function test_support_users_lists_only_active_role_holders(): void
    {
        $active = $this->supportUser();
        $inactive = $this->supportUser();
        $inactive->update(['is_active' => false]);
        User::factory()->create();

        $this->assertSame([$active->id], $this->service->supportUsers()->pluck('id')->all());
    }

    private function supportUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OtherBankSupportService::ROLE);

        return $user;
    }

    private function slab(string $month, float $minimum, float $amount): OtherBankIncentiveSlab
    {
        return OtherBankIncentiveSlab::factory()->create([
            'effective_month' => $month,
            'min_achievement' => $minimum,
            'payout_type' => OtherBankIncentiveSlab::PAYOUT_FIXED,
            'payout_value' => $amount,
        ]);
    }

    private function disbursed(string $bank, int $amount, string $date = '2026-08-10', int $cashback = 0, int $subvention = 0): Customer
    {
        return Customer::factory()->create([
            'eligibility_status' => 'eligible',
            'bank_eligible_for' => $bank,
            'sanctioned_bank' => $bank,
            'journey_status' => 'sanctioned',
            'disbursal_status' => 'disbursed',
            'disbursal_date' => $date,
            'sanctioned_loan_amount' => $amount,
            'cashback' => $cashback,
            'subvention' => $subvention,
            'docking' => 0,
        ]);
    }
}
