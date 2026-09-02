<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use App\Services\TopPerformerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The marquee (and every dashboard reusing TopPerformerService) previously
 * ran one achievement query PER caller — with dozens/hundreds of callers,
 * that's exactly the N+1 pattern Debugbar showed (300+ queries) blocking
 * the marquee's initial render. Callers are a flat, non-hierarchical group
 * (their achievement is just their own customers, no subordinate rollup),
 * so their whole group's achievement can be fetched in one batched query
 * instead — this proves that stays correct AND actually cuts the query
 * count, not just that the numbers still add up.
 */
class TopPerformerServiceCallerBatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_batched_caller_achievement_matches_the_per_employee_engine(): void
    {
        $callers = Employee::factory()->count(5)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'exit_status' => 'no',
        ]);

        foreach ($callers as $index => $caller) {
            Customer::factory()->create([
                'employee_id' => $caller->id,
                'sanctioned_bank' => $index % 2 === 0 ? 'BFL Prime' : 'HDFC Bank',
                'sanctioned_loan_amount' => ($index + 1) * 500000,
                'cashback' => 10000,
                'subvention' => 0,
                'docking' => '0',
                'disbursal_status' => 'disbursed',
                'disbursal_date' => now(),
            ]);
        }

        $calculator = new AchievementCalculatorService;

        $batched = $calculator->countAchievementByEmployeeId($callers->pluck('id'));

        foreach ($callers as $caller) {
            $this->assertSame(
                $calculator->getCountAchievement($caller),
                $batched[$caller->id],
                "Batched achievement for caller {$caller->id} must match the canonical per-employee engine."
            );
        }
    }

    public function test_caller_leaderboard_runs_a_bounded_number_of_queries_regardless_of_caller_count(): void
    {
        $callers = Employee::factory()->count(20)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'exit_status' => 'no',
        ]);

        foreach ($callers as $caller) {
            Customer::factory()->create([
                'employee_id' => $caller->id,
                'sanctioned_loan_amount' => 1000000,
                'cashback' => 0,
                'subvention' => 0,
                'docking' => '0',
                'disbursal_status' => 'disbursed',
                'disbursal_date' => now(),
            ]);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        (new TopPerformerService(new AchievementCalculatorService))->getTopPerformers(null);

        // One query to fetch the callers, one batched achievement query,
        // plus a small constant number for the Team Leader/Manager
        // sections (empty here) — must not scale with the 20 callers.
        $this->assertLessThan(20, $queryCount, "Expected a bounded query count, got {$queryCount} — caller achievement is no longer batched.");
    }
}
