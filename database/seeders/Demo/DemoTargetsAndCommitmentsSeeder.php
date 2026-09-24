<?php

namespace Database\Seeders\Demo;

use App\Enums\CommitmentResult;
use App\Enums\CommitmentStage;
use App\Models\Customer;
use App\Models\CustomerStageHistory;
use App\Models\DailyCallerOtp;
use App\Models\DailyCommitment;
use App\Models\Employee;
use App\Models\MonthlyCommitmentTarget;
use App\Models\OtherBankIncentiveSlab;
use App\Models\OtherBankSupportTarget;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\DailyCommitmentService;
use App\Services\MonthlyTargetGate;
use App\Services\OtherBankSupportService;
use Database\Seeders\Demo\Concerns\SeedsDemoTimeline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Targets and the Daily Commitment module.
 *
 *  - employee_targets: the LMS target row per in-tree seat (the category
 *    figure; AchievementCalculatorService derives hierarchy targets itself).
 *  - monthly_commitment_targets for this month and the two before it, for
 *    every Manager, Team Leader and Caller on the rolls with a login —
 *    MonthlyTargetGate closes the panel from the 1st without them.
 *  - daily_commitments for every working day of the month in progress:
 *    a morning promise, then the evening declaration of the cases that made
 *    the day. Declarations are built from real journey events (the stage a
 *    case reached that day), claim each mobile only once across the whole
 *    table, and are saved through DailyCommitmentService::replaceFulfilment
 *    so lms_highest_stage, achievement and result are computed by the
 *    module itself. Today's promise is made but left open for the evening.
 *  - daily_caller_otps for callers this month.
 *  - Other Bank Support monthly targets and incentive slab ladders.
 */
class DemoTargetsAndCommitmentsSeeder extends Seeder
{
    use SeedsDemoTimeline;

    /**
     * Stage-history status that marks the day a case reached each rung.
     *
     * @var array<string, string>
     */
    protected const EVENT_STAGES = [
        'Moved to Underwriting' => 'underwriting',
        'Moved to Approved' => 'approved',
        'Moved to Sanctioned' => 'disbursed',
    ];

    /**
     * @var array<int, User>
     */
    protected array $loginsByEmployee = [];

    public function run(): void
    {
        $this->loginsByEmployee = User::query()->whereNotNull('employee_id')->get()->keyBy('employee_id')->all();

        $this->seedEmployeeTargets();
        $this->seedMonthlyCommitmentTargets();
        $this->seedDailyCommitments();
        $this->seedCallerOtps();
        $this->seedOtherBankSupport();

        app(MonthlyTargetGate::class)->forget();
    }

    protected function seedEmployeeTargets(): void
    {
        $calculator = app(AchievementCalculatorService::class);
        $monthStart = $this->realNow()->copy()->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $rows = Employee::query()
            ->whereIn('designation', array_keys(Employee::DESIGNATION_RANKS))
            ->where('exit_status', '!=', 'yes')
            ->get()
            ->map(fn (Employee $employee): array => [
                'employee_id' => $employee->id,
                'target_amount' => $employee->designation === Employee::DESIGNATION_CALLER
                    ? $employee->target_amount
                    : $calculator->getTargetForPeriod($employee, $monthStart, $monthEnd),
            ])
            ->all();

        DB::table('employee_targets')->insert($rows);
    }

    protected function seedMonthlyCommitmentTargets(): void
    {
        foreach ([2, 1, 0] as $offset) {
            $month = $this->realNow()->copy()->startOfMonth()->subMonthsNoOverflow($offset);

            foreach ($this->commitmentEmployees($month) as $employee) {
                $setter = $this->setterFor($employee);
                [$stage, $amount, $count] = $this->monthlyTargetFor($employee);

                $this->replay($this->momentOn($month->copy()->addDays($offset === 0 ? 0 : 1), 9, 20), $setter, fn () => MonthlyCommitmentTarget::query()->create([
                    'employee_id' => $employee->id,
                    'month' => $month->toDateString(),
                    'stage' => $stage,
                    'target_amount' => $amount,
                    'target_count' => $count,
                ]));
            }
        }
    }

    /**
     * @return array{0: CommitmentStage, 1: float, 2: int}
     */
    protected function monthlyTargetFor(Employee $employee): array
    {
        return match ($employee->designation) {
            Employee::DESIGNATION_CALLER => ((int) $employee->id % 5 === 0)
                ? [CommitmentStage::Approved, (float) $employee->target_amount * 1.2, 0]
                : [CommitmentStage::Disbursed, (float) $employee->target_amount, 0],
            Employee::DESIGNATION_TEAM_LEADER => [CommitmentStage::Disbursed, 9000000.0, 0],
            default => [CommitmentStage::Disbursed, 25000000.0, 0],
        };
    }

    /**
     * Who sets a target: the nearest Manager/Cluster/Business Head seat
     * above a caller; the nearest Cluster/Business Head above a Team
     * Leader or Manager (MonthlyTargetGate::OWNER_SEATS).
     */
    protected function setterFor(Employee $employee): User
    {
        $columns = $employee->designation === Employee::DESIGNATION_CALLER
            ? ['manager_id', 'cluster_id', 'business_head_id']
            : ['cluster_id', 'business_head_id'];

        foreach ($columns as $column) {
            if ($employee->{$column} && isset($this->loginsByEmployee[$employee->{$column}])) {
                return $this->loginsByEmployee[$employee->{$column}];
            }
        }

        return User::role('Admin')->firstOrFail();
    }

    /**
     * Managers, Team Leaders and Callers on the rolls during $month who
     * have a login (MonthlyTargetGate::REQUIRES_TARGET / activeEmployees()).
     *
     * @return Collection<int, Employee>
     */
    protected function commitmentEmployees(Carbon $month): Collection
    {
        return Employee::query()
            ->whereIn('designation', MonthlyTargetGate::REQUIRES_TARGET)
            ->activeDuring($month->copy()->startOfMonth(), $month->copy()->endOfMonth())
            ->whereIn('id', array_keys($this->loginsByEmployee))
            ->orderBy('id')
            ->get();
    }

    protected function seedDailyCommitments(): void
    {
        $service = app(DailyCommitmentService::class);
        $today = $this->realNow()->copy()->startOfDay();
        $start = $today->copy()->startOfMonth();
        $events = $this->declarableEvents($start, $today);

        for ($day = $start->copy(); $day->lessThanOrEqualTo($today); $day->addDay()) {
            if (! $this->isWorkingDay($day)) {
                continue;
            }

            foreach ($this->commitmentEmployees($day) as $employee) {
                if (Carbon::parse($employee->doj)->greaterThan($day)
                    || ($employee->exit_date && Carbon::parse($employee->exit_date)->lessThan($day))) {
                    continue;
                }

                $user = $this->loginsByEmployee[$employee->id];
                $cases = $events[$day->toDateString()][$employee->id] ?? [];

                // Some quiet mornings nobody commits at all — the module does
                // not chase a day that was never committed to.
                if ($cases === [] && ! $day->isSameDay($today) && fake()->boolean(75)) {
                    continue;
                }

                $commitment = $this->replay($this->momentOn($day, 9, fake()->numberBetween(5, 45)), $user, fn (): DailyCommitment => $this->morningCommitment($employee, $day, $cases, $service));

                if ($day->isSameDay($today) && ($employee->id % 2 === 0 || $this->realNow()->hour < 18)) {
                    continue; // today's declaration is still to come
                }

                $this->replay($this->momentOn($day, fake()->numberBetween(17, 18), fake()->numberBetween(0, 29)), $user, function () use ($commitment, $cases, $service): void {
                    if ($cases === []) {
                        // MyDailyCommitment::declareNothing().
                        $commitment->forceFill([
                            'submitted_at' => now(),
                            'declaration_note' => fake()->randomElement(['Customers did not pick up.', 'All files stuck on documents.', 'Field visit day, no logins.', null]),
                        ])->save();

                        $service->syncCommitment($commitment->refresh());

                        return;
                    }

                    $service->replaceFulfilment($commitment, $cases, submit: true);
                });
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $cases
     */
    protected function morningCommitment(Employee $employee, Carbon $day, array $cases, DailyCommitmentService $service): DailyCommitment
    {
        $declared = collect($cases);
        $isCaller = $employee->designation === Employee::DESIGNATION_CALLER;
        $useOtp = $isCaller && fake()->boolean(15);

        // Most people commit at the stage their day actually lands on; some
        // aim one rung higher than they reach.
        $ladder = [CommitmentStage::Sfl, CommitmentStage::Underwriting, CommitmentStage::Approved, CommitmentStage::Disbursed];
        $reached = $declared->pluck('stage')
            ->map(fn (string $value): int => (int) array_search(CommitmentStage::from($value), $ladder, true))
            ->max();

        $stage = match (true) {
            $useOtp => CommitmentStage::Otp,
            $reached === null => fake()->randomElement([CommitmentStage::Disbursed, CommitmentStage::Approved, CommitmentStage::Sfl]),
            fake()->boolean(75) => $ladder[$reached],
            default => $ladder[min($reached + 1, 3)],
        };

        $stageIndex = array_search($stage, $ladder, true);
        $declaredTotal = (float) $declared
            ->filter(fn (array $case): bool => $stageIndex === false || array_search(CommitmentStage::from($case['stage']), $ladder, true) >= $stageIndex)
            ->sum('amount');
        $amount = $stage->isCount()
            ? 0.0
            : max(200000.0, round(($declaredTotal ?: fake()->numberBetween(2, 8) * 100000) * fake()->randomFloat(2, 0.6, 1.15) / 50000) * 50000);

        $commitment = DailyCommitment::query()->create([
            'employee_id' => $employee->id,
            'date' => $day->toDateString(),
            'commitment_stage' => $stage,
            'commitment_amount' => $amount,
            'commitment_count' => $stage->isCount() ? max(1, $declared->count() + fake()->numberBetween(-1, 2)) : 0,
            'result' => CommitmentResult::InProgress,
            'remarks' => fake()->boolean(30) ? fake()->randomElement(['Two files expected to disburse today.', 'Following up approvals from yesterday.', 'Focus on pending document collection.']) : null,
            'created_by' => auth()->id(),
        ]);

        return $service->syncCommitment($commitment);
    }

    /**
     * Journey events in the window, turned into declaration rows keyed by
     * day and declaring employee. Each case is declared once, on the day
     * it reached its highest stage in the window, by its caller or — for a
     * share of cases — by the Team Leader or Manager above them.
     *
     * @return array<string, array<int, array<int, array<string, mixed>>>>
     */
    protected function declarableEvents(Carbon $start, Carbon $today): array
    {
        $rank = ['otp' => 0, 'sfl' => 1, 'underwriting' => 2, 'approved' => 3, 'disbursed' => 4];

        $latestByCustomer = [];

        // A file logged in the window counts at SFL when eligible, and at the
        // OTP rung (a headcount, no amount) when it is not.
        foreach (Customer::query()->whereDate('created_at', '>=', $start->toDateString())->get() as $customer) {
            $latestByCustomer[$customer->id] = [
                'stage' => $customer->eligibility_status === 'eligible' ? 'sfl' : 'otp',
                'day' => $customer->created_at->copy()->startOfDay(),
                'customer' => $customer,
            ];
        }

        $histories = CustomerStageHistory::query()
            ->whereIn('status_value', array_keys(self::EVENT_STAGES))
            ->where('created_at', '>=', $start)
            ->orderBy('created_at')
            ->get();

        $customers = Customer::query()->whereIn('id', $histories->pluck('customer_id')->unique())->get()->keyBy('id');

        foreach ($histories as $history) {
            $stage = self::EVENT_STAGES[$history->status_value];
            $current = $latestByCustomer[$history->customer_id] ?? null;

            if ($current === null || $rank[$stage] >= $rank[$current['stage']]) {
                $latestByCustomer[$history->customer_id] = [
                    'stage' => $stage,
                    'day' => $history->created_at->copy()->startOfDay(),
                    'customer' => $customers[$history->customer_id] ?? $current['customer'] ?? null,
                ];
            }
        }

        $events = [];

        foreach ($latestByCustomer as $event) {
            /** @var Customer|null $customer */
            $customer = $event['customer'];

            if ($customer === null || ! $this->isWorkingDay($event['day']) || $event['day']->greaterThan($today)) {
                continue;
            }

            $owner = Employee::query()->find($customer->employee_id);

            if ($owner === null || fake()->boolean(5)) {
                continue; // not every case gets declared
            }

            $declarer = $this->declarerFor($owner);

            $amount = match ($event['stage']) {
                'otp' => 0.0,
                'disbursed' => (float) $customer->sanctioned_loan_amount,
                'approved' => (float) $customer->approved_loan_amount,
                default => (float) $customer->eligible_loan_amount,
            };

            $events[$event['day']->toDateString()][$declarer->id][] = [
                'customer_id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'mobile_no' => $customer->mobile_no,
                'reference' => $customer->application_no,
                'stage' => $event['stage'],
                'outcome' => match ($customer->journey_status) {
                    'not_approved' => CommitmentStage::Rejected->value,
                    'dropped' => CommitmentStage::Dropped->value,
                    default => null,
                },
                'amount' => $amount,
                'remarks' => null,
            ];
        }

        return $events;
    }

    /**
     * Mostly the caller who owns the case; sometimes their Team Leader or
     * Manager claims it on their own commitment instead.
     */
    protected function declarerFor(Employee $owner): Employee
    {
        if ($owner->designation !== Employee::DESIGNATION_CALLER || $owner->exit_status === 'yes') {
            return $owner;
        }

        $roll = fake()->numberBetween(1, 100);

        if ($roll > 85 && $owner->manager_id) {
            return Employee::query()->find($owner->manager_id) ?? $owner;
        }

        if ($roll > 55 && $owner->superviser_id) {
            return Employee::query()->find($owner->superviser_id) ?? $owner;
        }

        return $owner;
    }

    protected function seedCallerOtps(): void
    {
        $today = $this->realNow()->copy()->startOfDay();

        for ($day = $today->copy()->startOfMonth(); $day->lessThanOrEqualTo($today); $day->addDay()) {
            if (! $this->isWorkingDay($day)) {
                continue;
            }

            foreach ($this->commitmentEmployees($day)->where('designation', Employee::DESIGNATION_CALLER) as $caller) {
                if (Carbon::parse($caller->doj)->greaterThan($day)) {
                    continue;
                }

                $setter = $this->loginsByEmployee[$caller->superviser_id ?? $caller->manager_id] ?? null;

                $this->replay($this->momentOn($day, 9, 0), $setter, fn () => DailyCallerOtp::query()->create([
                    'employee_id' => $caller->id,
                    'date' => $day->toDateString(),
                    'expected_otp' => fake()->numberBetween(3, 6),
                    'set_by' => $setter?->id,
                ]));
            }
        }
    }

    protected function seedOtherBankSupport(): void
    {
        $admin = User::role('Admin')->firstOrFail();
        $supportUsers = User::role(OtherBankSupportService::ROLE)->orderBy('id')->get();
        $thisMonth = $this->realNow()->copy()->startOfMonth();

        foreach ([2, 1, 0] as $offset) {
            $month = $thisMonth->copy()->subMonthsNoOverflow($offset);

            foreach ($supportUsers as $index => $user) {
                $this->replay($this->momentOn($month, 10), $admin, fn () => OtherBankSupportTarget::query()->create([
                    'user_id' => $user->id,
                    'month' => $month->toDateString(),
                    'target_amount' => ($index === 0 ? 5000000 : 3500000) + $offset * 500000,
                ]));
            }
        }

        // The ladder that started three months ago...
        $this->slabLadder($thisMonth->copy()->subMonthsNoOverflow(2), [
            [1000000, OtherBankIncentiveSlab::PAYOUT_FIXED, 5000],
            [2500000, OtherBankIncentiveSlab::PAYOUT_FIXED, 12000],
            [5000000, OtherBankIncentiveSlab::PAYOUT_PERCENTAGE, 0.5],
        ], $admin);

        // ...replaced this month by a richer one.
        $this->slabLadder($thisMonth, [
            [1000000, OtherBankIncentiveSlab::PAYOUT_FIXED, 6000],
            [2500000, OtherBankIncentiveSlab::PAYOUT_FIXED, 15000],
            [4000000, OtherBankIncentiveSlab::PAYOUT_PERCENTAGE, 0.5],
            [6000000, OtherBankIncentiveSlab::PAYOUT_PERCENTAGE, 0.75],
        ], $admin);
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: float|int}>  $slabs
     */
    protected function slabLadder(Carbon $effectiveMonth, array $slabs, User $admin): void
    {
        foreach ($slabs as [$minimum, $type, $value]) {
            $this->replay($this->momentOn($effectiveMonth, 10, 30), $admin, fn () => OtherBankIncentiveSlab::query()->create([
                'effective_month' => $effectiveMonth->toDateString(),
                'min_achievement' => $minimum,
                'payout_type' => $type,
                'payout_value' => $value,
            ]));
        }
    }
}
