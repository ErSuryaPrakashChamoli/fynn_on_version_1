<?php

namespace Database\Seeders\DemoEnvironment;

use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Three months of customer journeys, sized off each caller's target so
 * the achievement, incentive, PPP and top-performer numbers come out
 * believable: the current month is part-way through, the two before it
 * are complete.
 *
 * Rows are inserted directly rather than walked through the journey
 * service, because every side effect of a stage change (stage history,
 * journey audit) is stamped with the signed-in user — here each one is
 * written explicitly with the person who would have made that move: the
 * Team Leader or Manager moves a file into underwriting and approves it,
 * the Manager disburses it, exactly as the live data shows.
 */
class CustomerJourneySeeder extends Seeder
{
    private DemoWorld $world;

    /** @var array<int, array<string, mixed>> */
    private array $customers = [];

    /** @var array<int, array<int, array{0: string, 1: string, 2: Carbon, 3: ?int, 4: ?string, 5: string}>> keyed by application_no index */
    private array $transitions = [];

    private int $applicationSequence = 0;

    /** @var array<int, int>|null users.id => employees.id */
    private ?array $employeeByUser = null;

    public function run(DemoWorld $world): void
    {
        $this->world = $world;

        foreach ($world->employeesWith(Employee::DESIGNATION_CALLER, activeOnly: false) as $caller) {
            $performance = $this->performanceFactor($caller);

            foreach ([2, 1, 0] as $monthOffset) {
                $this->seedCallerMonth($caller, $monthOffset, $performance);
            }
        }

        foreach ($world->employeesWith(Employee::DESIGNATION_TEAM_LEADER) as $teamLeader) {
            $this->seedDirectCustomers($teamLeader);
        }

        $this->persist();
    }

    /**
     * How hard each caller runs against target. The persona team is kept
     * comfortably above the Team Leader incentive gate (90–100%) so the
     * TL walkthrough shows a live incentive, with Ananya among the top.
     */
    private function performanceFactor(object $caller): float
    {
        if ($caller->id === ($this->world->personaEmployeeIds['caller'] ?? null)) {
            return 1.34;
        }

        if ($caller->superviser_id === ($this->world->personaEmployeeIds['team-leader'] ?? null)) {
            return mt_rand(108, 130) / 100;
        }

        if ($caller->manager_id === ($this->world->personaEmployeeIds['manager'] ?? null)) {
            return mt_rand(95, 125) / 100;
        }

        return mt_rand(55, 135) / 100;
    }

    private function seedCallerMonth(object $caller, int $monthOffset, float $performance): void
    {
        $monthStart = $this->world->monthStart($monthOffset);
        $windowStart = Carbon::parse($caller->reporting_date ?? $caller->doj)->max($monthStart);
        $windowEnd = $this->world->monthEndOrToday($monthOffset);

        if ($caller->exit_status === 'yes' && $caller->exit_date) {
            $windowEnd = $windowEnd->min(Carbon::parse($caller->exit_date));
        }

        if ($windowStart->greaterThan($windowEnd)) {
            return;
        }

        $daysInMonth = $monthStart->daysInMonth;
        $activeDays = $windowStart->diffInDays($windowEnd) + 1;
        $goal = (int) $caller->category * $performance * ($activeDays / $daysInMonth);

        // A new joiner ramps up: their first weeks close far fewer files.
        if (Carbon::parse($caller->doj)->greaterThan($monthStart->copy()->subMonth())) {
            $goal *= 0.55;
        }

        $achieved = 0;

        while ($achieved < $goal) {
            $achieved += $this->makeDisbursed($caller, $windowStart, $windowEnd);
        }

        $isLiveMonth = $monthOffset === 0 && $caller->exit_status === 'no';
        $pipeline = $isLiveMonth
            ? ['sfl' => mt_rand(2, 5), 'underwriting' => mt_rand(1, 4), 'approved' => mt_rand(1, 3), 'carry_forward' => mt_rand(0, 1), 'not_started' => mt_rand(2, 4), 'dropped' => mt_rand(0, 1), 'not_approved' => mt_rand(0, 1)]
            : ['not_started' => mt_rand(2, 5), 'dropped' => mt_rand(1, 2), 'not_approved' => mt_rand(1, 2), 'sfl' => $this->world->chance(0.3) ? 1 : 0];

        foreach ($pipeline as $stage => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->makeInProgress($caller, $stage, $windowStart, $windowEnd, $isLiveMonth);
            }
        }
    }

    /**
     * Team Leaders and Managers can log a "direct" customer of their own.
     */
    private function seedDirectCustomers(object $teamLeader): void
    {
        $monthStart = $this->world->monthStart();

        $this->makeDisbursed($teamLeader, $this->world->monthStart(1), $this->world->monthEndOrToday(1), direct: true);
        $lastDay = $this->world->monthEndOrToday();

        if ($monthStart->greaterThan($lastDay)) {
            return;
        }

        $this->makeDisbursed($teamLeader, $monthStart, $lastDay, direct: true);
        $this->makeInProgress($teamLeader, $this->world->pick(['sfl', 'underwriting', 'approved']), $monthStart, $lastDay, true, direct: true);
    }

    /**
     * @return int the file's net count-achievement, as AchievementCalculatorService scores it
     */
    private function makeDisbursed(object $owner, Carbon $from, Carbon $to, bool $direct = false): int
    {
        $disbursedOn = $this->randomDay($from, $to);
        $approvedAt = $this->world->timeOn($disbursedOn->copy()->subDays(mt_rand(1, 4)));
        $underwritingAt = $this->world->timeOn($approvedAt->copy()->subDays(mt_rand(1, 3)));
        $createdAt = $this->world->timeOn($underwritingAt->copy()->subDays(mt_rand(0, 3)))->min($underwritingAt);
        $disbursedAt = $this->world->timeOn($disbursedOn, 11, 19);

        $customer = $this->baseCustomer($owner, $createdAt, $direct);
        $bank = $this->world->weighted([
            'BFL Prime' => 24, 'BFL Growth' => 12, 'BFL SOL' => 8, 'BFL RSL' => 6,
            'HDFC Bank' => 8, 'ICICI Bank' => 7, 'Axis Bank' => 6, 'Tata Capital' => 6, 'Incred' => 5,
            'Poonawala' => 5, 'ABFL' => 5, 'Kotak Mahindra Bank' => 4, 'IDFC First Bank' => 4,
        ]);
        $amount = $this->loanAmount();
        $sanctioned = $this->world->chance(0.8) ? $amount : $amount - $this->world->amount(10000, 50000);
        $cashback = $this->world->chance(0.18) ? $this->world->pick([250, 500, 750, 1000]) : 0;
        $subvention = $this->world->chance(0.08) ? $this->world->pick([250, 500]) : 0;
        $multiplier = in_array($bank, ['BFL Prime', 'BFL Growth'], true) ? 50 : 100;
        $documentsIn = $disbursedOn->lessThan($this->world->today->copy()->subDays(4)) ? $this->world->chance(0.92) : $this->world->chance(0.45);

        $customer = array_merge($customer, $this->approvedColumns($amount, $bank, $approvedAt), [
            'bank_eligible_for' => $this->world->chance(0.85) ? $bank : $customer['bank_eligible_for'],
            'journey_status' => 'sanctioned',
            'disbursal_status' => 'disbursed',
            'disbursal_date' => $disbursedOn->toDateString(),
            'disbursal_finalized' => true,
            'documents_submitted' => $documentsIn,
            'channel' => $this->world->chance(0.7) ? $this->world->weighted(['finance_buddha' => 45, 'ruloans' => 15, 'profin_care' => 12, 'kms_finbud' => 10, 'fast_credit' => 10, 'rare_crome' => 8]) : null,
            'sanctioned_loan_amount' => $sanctioned,
            'cashback' => $cashback,
            'subvention' => $subvention,
            'docking' => null,
            'payout_rate' => $this->world->pick([1.5, 1.75, 2.0, 2.25, 2.5, 2.75, 3.0]),
            'attachment_required' => 'no',
            'sanctioned_remarks' => $this->world->pick(['Disbursed to salary account.', 'Disbursal confirmed by bank.', 'Customer confirmed receipt.', 'Disbursed after e-NACH registration.']),
            'updated_at' => $disbursedAt,
        ]);

        $manager = $this->managerFor($owner);
        $mover = $this->moverFor($owner);

        $this->record($customer, [
            ['Sfl Stage', 'Moved to Underwriting', $underwritingAt, $mover, 'document_verification', 'underwriting'],
            ['Underwriting Stage', 'Moved to Approved', $approvedAt, $mover, 'approval', 'approved'],
            ['Underwriting', 'Moved to Credit Approval', $approvedAt, $mover, null, 'approved'],
            ['Approved Stage', 'Moved to Sanctioned', $disbursedAt, $manager, 'bank_processing', 'sanctioned'],
            ['Credit Approval', 'Moved to Disbursal', $disbursedAt, $manager, null, 'sanctioned'],
            ...($documentsIn ? [
                ['Disbursal Documents', 'Documents Submitted', $disbursedAt->copy()->addHours(mt_rand(2, 30))->min($this->world->now), $manager, null, 'sanctioned'],
                ['Disbursal', 'Disbursal Finalized', $disbursedAt->copy()->addHours(mt_rand(30, 50))->min($this->world->now), $manager, null, 'sanctioned'],
            ] : []),
        ]);

        return (int) ($sanctioned - ($cashback + $subvention) * $multiplier);
    }

    private function makeInProgress(object $owner, string $stage, Carbon $from, Carbon $to, bool $isLiveMonth, bool $direct = false): void
    {
        // Live files entered their current stage recently; the stage clock
        // is what the SLA and pending-case screens age them by.
        $stageEnteredAt = $isLiveMonth
            ? $this->world->timeOn($this->randomDay($this->world->today->copy()->subDays(6)->max($from), $to))
            : $this->world->timeOn($this->randomDay($from, $to));

        $mover = $this->moverFor($owner);
        $manager = $this->managerFor($owner);

        if ($stage === 'not_started') {
            $customer = $this->baseCustomer($owner, $stageEnteredAt, $direct);
            $pending = $this->world->chance(0.25) && DB::getDriverName() === 'mysql';
            $customer['eligibility_status'] = $pending ? 'consent_pending' : 'not_eligible';
            $customer['eligibility_reason'] = $pending ? null : $this->world->weighted(['cibil_score' => 32, 'defaulter_bounces' => 28, 'company_not_listed' => 14, 'low_salary' => 14, 'location_issue' => 6, 'no_residence_proof' => 6]);
            $customer['journey_status'] = 'not_started';
            $customer['lan_no'] = null;
            $this->record($customer, []);

            return;
        }

        $createdAt = $stage === 'sfl'
            ? $stageEnteredAt
            : $this->world->timeOn($stageEnteredAt->copy()->subDays(mt_rand(1, 4)));
        $customer = $this->baseCustomer($owner, $createdAt, $direct);
        $amount = $this->loanAmount();
        $underwritingAt = $stage === 'underwriting' ? $stageEnteredAt : $this->world->timeOn($createdAt->copy()->addDays(mt_rand(0, 1))->min($stageEnteredAt));
        $transitions = [];

        if ($stage === 'sfl') {
            $customer['documentation_status'] = $this->world->chance(0.4) ? 'complete' : 'pending';
            $customer['pending_document'] = $customer['documentation_status'] === 'pending'
                ? json_encode(array_values(array_unique([$this->world->pick(['bank_statement', 'payslip', 'form_26as']), $this->world->pick(['current_address_proof', 'electricity_bill', 'photo'])])))
                : null;
            $customer['sfl_remarks'] = $this->world->pick(['Login done, awaiting documents.', 'Customer to share 3 months payslips.', 'Documents collected over WhatsApp.', 'KYC verified, bank statement pending.']);
        } else {
            $transitions[] = ['Sfl Stage', 'Moved to Underwriting', $underwritingAt, $mover, 'document_verification', 'underwriting'];
            $customer['underwriting_status'] = 'in_process';
            $customer['underwriting_remarks'] = $this->world->pick(['File logged with credit.', 'Credit reviewing bank statement.', 'Awaiting CIBIL refresh.']);
        }

        if (in_array($stage, ['approved', 'carry_forward', 'dropped'], true)) {
            $bank = $this->world->pick([...DemoWorld::IN_HOUSE_BANKS, ...DemoWorld::OTHER_BANKS]);
            $customer = array_merge($customer, $this->approvedColumns($amount, $bank, $stageEnteredAt));
            $transitions[] = ['Underwriting Stage', 'Moved to Approved', $stageEnteredAt, $mover, 'approval', 'approved'];
            $transitions[] = ['Underwriting', 'Moved to Credit Approval', $stageEnteredAt, $mover, null, 'approved'];
        }

        if ($stage === 'carry_forward') {
            $customer['disbursal_status'] = 'carry_forward';
            $customer['carry_forward_date'] = $this->world->monthStart()->addMonthNoOverflow()->addDays(mt_rand(1, 8))->toDateString();
            $customer['sanctioned_remarks'] = 'Customer asked to disburse next month.';
        }

        if ($stage === 'dropped') {
            $droppedAt = $this->world->timeOn($stageEnteredAt->copy()->addDays(mt_rand(1, 3))->min($this->world->today));
            $customer['journey_status'] = 'dropped';
            $customer['disbursal_status'] = 'dropped';
            $customer['disbursal_finalized'] = true;
            $customer['sanctioned_remarks'] = $this->world->pick(['Customer took the loan from another lender.', 'Customer no longer needs the loan.', 'ROI not acceptable to the customer.']);
            $transitions[] = ['Approved Stage', 'Moved to Dropped', $droppedAt, $manager, 'bank_processing', 'dropped'];
            $transitions[] = ['Credit Approval', 'Application Dropped', $droppedAt, $manager, null, 'dropped'];
        }

        if ($stage === 'not_approved') {
            $customer['underwriting_status'] = 'rejected';
            $customer['journey_not_approved_reason'] = $this->world->pick(['cibil_score', 'defaulter_bounces', 'low_salary', 'no_residence_proof', 'location_issue']);
            $customer['not_approved_remarks'] = $this->world->pick(['Rejected by credit on bureau.', 'Bounces in last 6 months.', 'Income below bank norms.']);
            $transitions[] = ['Underwriting Stage', 'Moved to Not approved', $stageEnteredAt, $mover, 'approval', 'not_approved'];
            $transitions[] = ['Underwriting', 'Customer Rejected', $stageEnteredAt, $mover, null, 'not_approved'];
        }

        // A carry-forward is saved from the disbursal step without being
        // finalised, so the file itself stays "approved".
        $customer['journey_status'] = match ($stage) {
            'carry_forward' => 'approved',
            default => $stage,
        };

        $customer['updated_at'] = $stageEnteredAt;
        $this->record($customer, $transitions);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseCustomer(object $owner, Carbon $createdAt, bool $direct): array
    {
        $first = $this->world->faker->firstName();
        $last = $this->world->faker->lastName();
        $salary = $this->world->amount(28000, 185000, 500);
        $city = $this->world->pick($this->world->cities);

        return [
            'customer_name' => "{$first} {$last}",
            'mobile_no' => $this->world->mobile(),
            'email' => $this->world->email("{$first} {$last}", $this->world->pick(['gmail.com', 'yahoo.co.in', 'outlook.com', 'rediffmail.com'])),
            'pan_number' => $this->world->pan($last),
            'job_location' => $city,
            'residence_location' => $this->world->chance(0.8) ? $city : $this->world->pick($this->world->cities),
            'current_location' => $city,
            'salary' => $salary,
            'eligible_loan_amount' => min(4000000, (int) (round($salary * mt_rand(12, 22) / 10000) * 10000)),
            'company_category' => $this->world->pick(DemoWorld::COMPANIES),
            'bank_eligible_for' => $this->world->chance(0.62) ? $this->world->pick(DemoWorld::IN_HOUSE_BANKS) : $this->world->pick(DemoWorld::OTHER_BANKS),
            'loan_applied' => $this->world->weighted(['personal_loan' => 74, 'overdraft' => 12, 'business_loan' => 5, 'home_loan' => 4, 'car_loan' => 3, 'education_loan' => 2]),
            'eligibility_status' => 'eligible',
            'journey_status' => 'sfl',
            'documentation_status' => 'complete',
            'application_no' => 'FA'.$createdAt->format('ymd').str_pad((string) (++$this->applicationSequence), 6, '0', STR_PAD_LEFT),
            'lan_no' => (string) mt_rand(1100000000, 1999999999).mt_rand(100000, 999999),
            'employee_id' => $owner->id,
            'assign_to' => $owner->id,
            'direct' => $direct,
            'credit_approval_completed' => false,
            'documents_submitted' => false,
            'disbursal_finalized' => false,
            'account_verified' => false,
            'incentive_calculated' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approvedColumns(int $amount, string $bank, Carbon $approvedAt): array
    {
        return [
            'journey_status' => 'approved',
            'documentation_status' => 'complete',
            'underwriting_status' => 'approved',
            'underwriting_remarks' => 'Credit approved.',
            'approved_loan_amount' => $amount,
            'approval_date' => $approvedAt->toDateString(),
            'sanctioned_bank' => $bank,
            'approved_remarks' => $this->world->pick(['Approved at requested amount.', 'Approved, ROI 11.5%.', 'Approved with 2% processing fee.', 'Approved subject to e-NACH.']),
            'credit_approval_completed' => true,
        ];
    }

    private function loanAmount(): int
    {
        return match ($this->world->weighted(['small' => 30, 'medium' => 45, 'large' => 20, 'jumbo' => 5])) {
            'small' => $this->world->amount(100000, 250000),
            'medium' => $this->world->amount(250000, 500000),
            'large' => $this->world->amount(500000, 900000),
            default => $this->world->amount(900000, 1500000, 10000),
        };
    }

    /** The Team Leader moves most files; the Manager steps in for the rest. */
    private function moverFor(object $owner): ?int
    {
        $teamLeader = (int) $owner->designation === Employee::DESIGNATION_CALLER ? $owner->superviser_id : $owner->id;

        return $this->world->chance(0.72)
            ? ($this->world->userIdFor($teamLeader) ?? $this->managerFor($owner))
            : $this->managerFor($owner);
    }

    private function managerFor(object $owner): ?int
    {
        return $this->world->userIdFor($owner->manager_id) ?? $this->world->personaUserIds['admin'];
    }

    private function randomDay(Carbon $from, Carbon $to): Carbon
    {
        $span = max(0, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()));

        return $from->copy()->startOfDay()->addDays(mt_rand(0, (int) $span));
    }

    /**
     * @param  array<string, mixed>  $customer
     * @param  array<int, array{0: string, 1: string, 2: Carbon, 3: ?int, 4: ?string, 5: string}>  $transitions
     */
    private function record(array $customer, array $transitions): void
    {
        $this->customers[] = $customer;
        $this->transitions[count($this->customers) - 1] = $transitions;
    }

    private function persist(): void
    {
        $columns = array_unique(array_merge(...array_map('array_keys', $this->customers)));
        $template = array_fill_keys($columns, null);

        $this->world->insert('customers', array_map(
            fn (array $row): array => array_merge($template, $row),
            $this->customers,
        ));

        $ids = DB::table('customers')->pluck('id', 'application_no');
        $histories = [];
        $audits = [];

        foreach ($this->customers as $index => $customer) {
            $customerId = $ids[$customer['application_no']];

            foreach ($this->transitions[$index] as [$stageName, $statusValue, $at, $userId, $module, $journeyStage]) {
                $histories[] = [
                    'customer_id' => $customerId,
                    'stage_name' => $stageName,
                    'status_value' => $statusValue,
                    'user_id' => $userId,
                    'created_at' => $at,
                    'updated_at' => $at,
                ];

                if ($module !== null) {
                    $audits[] = [
                        'customer_id' => $customerId,
                        'journey_stage' => $journeyStage,
                        'module' => $module,
                        'action' => $statusValue,
                        'access_type' => 'normal',
                        'original_owner_id' => $customer['assign_to'],
                        'acting_employee_id' => $this->employeeForUser($userId),
                        'is_admin_override' => false,
                        'performed_by' => $userId,
                        'performed_at' => $at,
                        'created_at' => $at,
                        'updated_at' => $at,
                    ];
                }
            }
        }

        usort($histories, fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);
        usort($audits, fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);

        $this->world->insert('customer_stage_histories', $histories);
        $this->world->insert('customer_journey_audits', $audits);
    }

    private function employeeForUser(?int $userId): ?int
    {
        $this->employeeByUser ??= array_flip($this->world->userIdByEmployee);

        return $userId ? ($this->employeeByUser[$userId] ?? null) : null;
    }
}
