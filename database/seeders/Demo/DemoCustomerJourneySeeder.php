<?php

namespace Database\Seeders\Demo;

use App\Enums\OtherBankRemarkStage;
use App\Models\City;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\OtherBankSupportRemark;
use App\Models\User;
use App\Services\CustomerJourneyService;
use App\Services\OtherBankSupportService;
use Database\Seeders\Demo\Concerns\DemoSeedState;
use Database\Seeders\Demo\Concerns\SeedsDemoTimeline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The loan book: customers spread over the current and previous three
 * months, each walked through the journey by the real CustomerJourneyService
 * with the clock set to the day it happened and the right person signed in.
 *
 * That is what makes the rest of the demo honest: stage histories, journey
 * audits and activity-log rows are written by the model hooks exactly as in
 * production, CustomerObserver opens the settlement when a case is
 * finalised, and every report reading them (achievement by disbursal date,
 * funnel counts by stage-history user, daily-commitment LMS stage) sees
 * genuine data rather than rows typed in afterwards.
 *
 * Who acts, as on the live floor: the caller logs the file (SFL), their
 * Team Leader moves it to underwriting, the Manager (or the nearest senior
 * seat when a level is skipped) approves, rejects and records the disbursal
 * outcome.
 */
class DemoCustomerJourneySeeder extends Seeder
{
    use SeedsDemoTimeline;

    /**
     * Outcome weights per month offset (0 = current month). Older months
     * are mostly closed; the current month is mostly still in flight.
     *
     * @var array<int, array<string, int>>
     */
    protected const OUTCOME_WEIGHTS = [
        0 => ['not_started' => 8, 'sfl' => 20, 'underwriting' => 18, 'approved' => 16, 'not_approved' => 6, 'sanctioned' => 22, 'carry_forward' => 4, 'dropped' => 4, 'completed' => 2],
        1 => ['not_started' => 7, 'sfl' => 4, 'underwriting' => 8, 'approved' => 12, 'not_approved' => 8, 'sanctioned' => 33, 'carry_forward' => 10, 'dropped' => 8, 'completed' => 10],
        2 => ['not_started' => 8, 'sfl' => 0, 'underwriting' => 3, 'approved' => 6, 'not_approved' => 10, 'sanctioned' => 38, 'carry_forward' => 7, 'dropped' => 10, 'completed' => 18],
        3 => ['not_started' => 8, 'sfl' => 0, 'underwriting' => 0, 'approved' => 3, 'not_approved' => 12, 'sanctioned' => 37, 'carry_forward' => 3, 'dropped' => 12, 'completed' => 25],
    ];

    /**
     * In-house lenders (weighted) and the "other bank" pool the Other Bank
     * Support desk works.
     *
     * @var array<string, int>
     */
    protected const IN_HOUSE_BANKS = ['BFL Prime' => 45, 'BFL Growth' => 25, 'BFL SOL' => 15, 'BFL RSL' => 15];

    /** @var list<string> */
    protected const OTHER_BANKS = ['ABFL', 'Incred', 'Tata Capital', 'Poonawala', 'Fibe', 'Axis Bank', 'HDFC Bank', 'IDFC First Bank'];

    /** @var list<string> */
    protected const COMPANIES = [
        'Meridian Softworks Pvt Ltd', 'Bluestone Analytics', 'Vantage Retail India', 'Northline Logistics',
        'Crestpoint Consulting', 'Auralink Technologies', 'Sunhaven Healthcare', 'Ironwood Manufacturing',
        'Skyfare Travels', 'Quantum Ledger Services', 'Tarang Media Works', 'Harbourline Shipping Services',
    ];

    /** @var list<string> */
    protected const FIRST_NAMES = [
        'Aakash', 'Bhavna', 'Chetan', 'Disha', 'Eshan', 'Farah', 'Gaurav', 'Heena', 'Ishaan', 'Jyoti',
        'Kunal', 'Lata', 'Mohit', 'Nidhi', 'Omkar', 'Pallavi', 'Qasim', 'Rashmi', 'Siddharth', 'Tanvi',
        'Uday', 'Vandana', 'Yash', 'Zoya', 'Abhinav', 'Charu', 'Dhruv', 'Ira', 'Manav', 'Payal',
    ];

    /** @var list<string> */
    protected const LAST_NAMES = [
        'Arora', 'Bhatia', 'Chopra', 'Desai', 'Grover', 'Hegde', 'Jain', 'Khanna', 'Luthra', 'Mishra',
        'Pandey', 'Rao', 'Saxena', 'Tiwari', 'Upadhyay', 'Wadhwa', 'Bose', 'Pillai', 'Sethi', 'Kulkarni',
    ];

    /** @var list<string> */
    protected const CHANNELS = ['finance_buddha', 'profin_care', 'rare_crome', 'ruloans', 'fast_credit', 'kms_finbud'];

    /** @var array<string, string> */
    protected const NOT_ELIGIBLE_REASONS = [
        'company_not_listed' => 'Employer not on the lender list.',
        'cibil_score' => 'CIBIL below the lender cut-off.',
        'low_salary' => 'Net salary below the minimum for the product.',
        'location_issue' => 'Residence pincode outside serviceable area.',
    ];

    /** @var list<string> */
    protected const NOT_APPROVED_REASONS = [
        'High existing EMI obligations (FOIR above policy).',
        'Negative credit bureau remark on an old account.',
        'Employer category downgraded by credit.',
        'Bank statement shows irregular salary credits.',
    ];

    /** @var list<string> */
    protected const DROP_REASONS = [
        'Customer took a better ROI offer elsewhere.',
        'Customer no longer needs the loan.',
        'Customer unwilling to pay processing fee.',
    ];

    /**
     * @var Collection<int, string>
     */
    protected Collection $cityNames;

    /**
     * @var array<int, User>
     */
    protected array $loginsByEmployee = [];

    /**
     * @var Collection<int, User>
     */
    protected Collection $otherBankUsers;

    protected int $nameCursor = 0;

    public function run(): void
    {
        $this->cityNames = City::query()->pluck('city');
        $this->loginsByEmployee = User::query()->whereNotNull('employee_id')->get()->keyBy('employee_id')->all();
        $this->otherBankUsers = User::role(OtherBankSupportService::ROLE)->get();

        $callers = Employee::query()
            ->where('designation', Employee::DESIGNATION_CALLER)
            ->orderBy('id')
            ->get();

        foreach ($callers as $caller) {
            foreach ([3, 2, 1, 0] as $monthOffset) {
                for ($file = $this->filesFor($caller, $monthOffset); $file > 0; $file--) {
                    $this->seedFile($caller, $monthOffset);
                }
            }
        }

        $this->seedDirectCustomers();
    }

    /**
     * How many files a caller logged in a month: none before joining or
     * after leaving, a handful for a new joiner, more in the month in progress.
     */
    protected function filesFor(Employee $caller, int $monthOffset): int
    {
        $monthStart = $this->realNow()->copy()->startOfMonth()->subMonthsNoOverflow($monthOffset);
        $monthEnd = $monthStart->copy()->endOfMonth();

        if (Carbon::parse($caller->doj)->greaterThan($monthEnd)) {
            return 0;
        }

        if ($caller->exit_date && Carbon::parse($caller->exit_date)->lessThan($monthStart)) {
            return 0;
        }

        if (Carbon::parse($caller->doj)->greaterThan($monthStart)) {
            return 4;
        }

        // The month in progress carries the live pipeline the daily
        // commitments are declared from, so it is the busiest.
        return $monthOffset === 0 ? fake()->numberBetween(16, 20) : fake()->numberBetween(4, 6);
    }

    protected function seedFile(Employee $caller, int $monthOffset): void
    {
        $createdOn = $this->loggingDay($caller, $monthOffset);

        if ($createdOn === null) {
            return;
        }

        $outcome = fake()->randomElement($this->weightedPool(self::OUTCOME_WEIGHTS[$monthOffset]));
        $plan = $this->planTimeline($createdOn, $outcome);

        $callerUser = $this->loginsByEmployee[$caller->id];
        $teamLeaderUser = $this->loginFor($caller->superviser_id) ?? $this->seniorFor($caller);
        $managerUser = $this->seniorFor($caller);

        $profile = $this->customerProfile($plan['outcome']);

        $lead = null;

        if ($plan['outcome'] !== 'not_started' && fake()->boolean(25)) {
            $lead = $this->replay($this->momentOn($createdOn->copy()->subDays(fake()->numberBetween(2, 6)), 11, 15), $callerUser, fn (): Lead => Lead::query()->create([
                'employee_id' => $caller->id,
                'customer_name' => $profile['customer_name'],
                'mobile_no' => $profile['mobile_no'],
                'email' => $profile['email'],
                'pan_number' => $profile['pan_number'],
                'current_location' => $profile['current_location'],
                'job_location' => $profile['job_location'],
                'residence_location' => $profile['residence_location'],
                'salary' => $profile['salary'],
                'follow_up_type' => 'Call',
                'status' => 'Interested',
                'next_follow_up_date' => $createdOn->copy()->setTime(11, 0),
                'remarks' => 'Interested in a personal loan, asked for eligibility check.',
            ]));
        }

        /** @var Customer $customer */
        $customer = $this->replay($this->momentOn($createdOn, fake()->numberBetween(10, 16), fake()->numberBetween(0, 59)), $callerUser, function () use ($caller, $profile): Customer {
            $eligible = $profile['eligibility_status'] === 'eligible';

            return Customer::query()->create([
                ...$profile,
                'employee_id' => $caller->id,
                'assign_to' => $caller->id,
                'direct' => false,
                // CreateCustomer::mutateFormDataBeforeCreate().
                'journey_status' => $eligible ? 'sfl' : 'not_started',
            ]);
        });

        if ($lead !== null) {
            // CreateCustomer::afterCreate() — a query update, no follow-up row.
            Lead::query()->whereKey($lead->id)->update([
                'is_converted' => true,
                'converted_customer_id' => $customer->id,
            ]);
        }

        $this->walkJourney($customer, $plan, $teamLeaderUser, $managerUser);

        $this->seedCustomerFollowUps($customer->fresh(), $caller, $callerUser, $plan);
    }

    /**
     * A working day inside the month on which the caller was on the rolls.
     */
    protected function loggingDay(Employee $caller, int $monthOffset): ?Carbon
    {
        $monthStart = $this->realNow()->copy()->startOfMonth()->subMonthsNoOverflow($monthOffset);
        $first = $monthStart->copy()->max(Carbon::parse($caller->doj));
        $last = $monthOffset === 0 ? $this->realNow()->copy()->startOfDay() : $monthStart->copy()->endOfMonth()->startOfDay();

        if ($caller->exit_date) {
            $last = $last->min(Carbon::parse($caller->exit_date));
        }

        if ($first->greaterThan($last)) {
            return null;
        }

        $day = $first->copy()->addDays(fake()->numberBetween(0, (int) $first->diffInDays($last)));

        if (! $this->isWorkingDay($day)) {
            $day = $day->copy()->subDay()->greaterThanOrEqualTo($first) ? $day->copy()->subDay() : $day->copy()->addDay();
        }

        return $day->greaterThan($last) ? null : $day->startOfDay();
    }

    /**
     * Dates for each journey step. A step that would land after today is
     * not taken, so a case logged recently simply stops at an earlier stage.
     *
     * @return array{outcome: string, created: Carbon, underwriting: ?Carbon, decision: ?Carbon, disbursal: ?Carbon, completion: ?Carbon}
     */
    protected function planTimeline(Carbon $createdOn, string $outcome): array
    {
        $today = $this->realNow()->copy()->startOfDay();
        $plan = ['outcome' => $outcome, 'created' => $createdOn, 'underwriting' => null, 'decision' => null, 'disbursal' => null, 'completion' => null];

        if (in_array($outcome, ['not_started', 'sfl'], true)) {
            return $plan;
        }

        $underwriting = $this->nextWorkingDay($createdOn->copy()->addDays(fake()->numberBetween(1, 3)));

        if ($underwriting->greaterThan($today)) {
            $plan['outcome'] = 'sfl';

            return $plan;
        }

        $plan['underwriting'] = $underwriting;

        if ($outcome === 'underwriting') {
            return $plan;
        }

        $decision = $this->nextWorkingDay($underwriting->copy()->addDays(fake()->numberBetween(2, 5)));

        if ($decision->greaterThan($today)) {
            $plan['outcome'] = 'underwriting';

            return $plan;
        }

        $plan['decision'] = $decision;

        if (in_array($outcome, ['approved', 'not_approved'], true)) {
            return $plan;
        }

        $disbursal = $this->nextWorkingDay($decision->copy()->addDays(fake()->numberBetween(2, 7)));

        if ($disbursal->greaterThan($today)) {
            $plan['outcome'] = 'approved';

            return $plan;
        }

        $plan['disbursal'] = $disbursal;

        if ($outcome !== 'completed') {
            return $plan;
        }

        $completion = $this->nextWorkingDay($disbursal->copy()->addDays(fake()->numberBetween(3, 10)));

        if ($completion->greaterThan($today)) {
            $plan['outcome'] = 'sanctioned';

            return $plan;
        }

        $plan['completion'] = $completion;

        return $plan;
    }

    /**
     * @param  array{outcome: string, created: Carbon, underwriting: ?Carbon, decision: ?Carbon, disbursal: ?Carbon, completion: ?Carbon}  $plan
     */
    protected function walkJourney(Customer $customer, array $plan, User $teamLeaderUser, User $managerUser): void
    {
        $outcome = $plan['outcome'];
        $otherBank = ! array_key_exists((string) $customer->bank_eligible_for, self::IN_HOUSE_BANKS)
            && $customer->eligibility_status === 'eligible';

        if ($otherBank) {
            $this->otherBankRemark($customer, OtherBankRemarkStage::Sfl, $plan['created'], 'File received from sales — checking lender policy for the employer.');
        }

        if ($plan['underwriting'] === null) {
            return;
        }

        $customer = $this->replay($this->momentOn($plan['underwriting'], 11, fake()->numberBetween(0, 59)), $teamLeaderUser, fn (): Customer => CustomerJourneyService::moveToUnderwriting($customer));

        if ($otherBank) {
            $this->otherBankRemark($customer, OtherBankRemarkStage::Underwriting, $plan['underwriting'], 'Login done on the lender portal, awaiting credit call.');
        }

        if ($plan['decision'] === null) {
            return;
        }

        if ($outcome === 'not_approved') {
            $this->replay($this->momentOn($plan['decision'], 15, fake()->numberBetween(0, 59)), $managerUser, function () use ($customer): void {
                $reason = fake()->randomElement(self::NOT_APPROVED_REASONS);

                $customer->update([
                    'journey_not_approved_reason' => $reason,
                    'not_approved_remarks' => $reason,
                    'underwriting_remarks' => 'Credit review completed.',
                ]);

                CustomerJourneyService::reject($customer);
            });

            if ($otherBank) {
                $this->otherBankRemark($customer, OtherBankRemarkStage::Underwriting, $plan['decision'], 'Lender declined the file — informed the sales team.');
            }

            return;
        }

        $approvedAmount = $this->roundTo((float) $customer->eligible_loan_amount * fake()->randomFloat(2, 0.8, 1.0), 10000);

        $customer = $this->replay($this->momentOn($plan['decision'], 15, fake()->numberBetween(0, 59)), $managerUser, fn (): Customer => CustomerJourneyService::approve($customer, [
            'approved_loan_amount' => (string) $approvedAmount,
            'sanctioned_bank' => $customer->bank_eligible_for,
            'approved_remarks' => 'Approved by credit at '.$this->lakhs($approvedAmount).'.',
            'approval_date' => $plan['decision']->toDateString(),
            'underwriting_remarks' => 'Documents verified, bureau and banking in order.',
        ]));

        if ($otherBank) {
            $this->otherBankRemark($customer, OtherBankRemarkStage::CreditApproval, $plan['decision'], 'Sanction letter received from the lender.');
        }

        if ($plan['disbursal'] === null) {
            return;
        }

        $disbursalStatus = match ($outcome) {
            'dropped' => 'dropped',
            'carry_forward' => 'carry_forward',
            default => 'disbursed',
        };

        $sanctionedAmount = $this->roundTo($approvedAmount * fake()->randomFloat(2, 0.9, 1.0), 5000);

        $customer = $this->replay($this->momentOn($plan['disbursal'], 17, fake()->numberBetween(0, 59)), $managerUser, function () use ($customer, $plan, $disbursalStatus, $sanctionedAmount): Customer {
            // Saved on the disbursal step of the form before the outcome is recorded.
            $customer->update(['payout_rate' => fake()->randomElement([1.5, 1.75, 2.0, 2.25, 2.5, 2.75, 3.0])]);

            return CustomerJourneyService::sanction($customer, [
                'disbursal_status' => $disbursalStatus,
                'disbursal_date' => $plan['disbursal']->toDateString(),
                'channel' => $disbursalStatus === 'carry_forward' ? null : fake()->randomElement(self::CHANNELS),
                'sanctioned_loan_amount' => $disbursalStatus === 'disbursed' ? (string) $sanctionedAmount : null,
                'cashback' => $disbursalStatus === 'disbursed' && fake()->boolean(35) ? (string) fake()->randomElement([1000, 1500, 2000, 2500, 3000, 5000]) : null,
                'subvention' => $disbursalStatus === 'disbursed' && fake()->boolean(20) ? (string) $this->roundTo($sanctionedAmount * 0.01, 500) : null,
                'docking' => $disbursalStatus === 'disbursed' && fake()->boolean(20) ? (string) fake()->randomElement([500, 1000, 1500, 2500]) : null,
                'carry_forward_date' => $disbursalStatus === 'carry_forward' ? $plan['disbursal']->copy()->addMonthNoOverflow()->startOfMonth()->addDays(4)->toDateString() : null,
                'sanctioned_remarks' => match ($disbursalStatus) {
                    'dropped' => fake()->randomElement(self::DROP_REASONS),
                    'carry_forward' => 'Customer asked to disburse next month.',
                    default => 'Disbursed to the customer account.',
                },
            ]);
        });

        if ($disbursalStatus === 'disbursed') {
            $customer = $this->replay($this->momentOn($plan['disbursal'], 17, 45), $managerUser, fn (): Customer => CustomerJourneyService::finalize($customer));
        }

        if ($otherBank) {
            $this->otherBankRemark($customer, OtherBankRemarkStage::Disbursal, $plan['disbursal'], match ($disbursalStatus) {
                'dropped' => 'Customer dropped after sanction — closing the file.',
                'carry_forward' => 'Disbursal moved to next month at the customer\'s request.',
                default => 'Disbursal confirmed by the lender.',
            });
        }

        if ($plan['completion'] !== null) {
            $this->replay($this->momentOn($plan['completion'], 12, fake()->numberBetween(0, 59)), $managerUser, fn (): Customer => CustomerJourneyService::submit($customer));
        }
    }

    /**
     * Follow-ups the caller logs against open files, so the calendars have
     * something due in the coming days. The newest row per customer is the
     * one that counts (see FollowUp::latestPerSubject()).
     *
     * @param  array{outcome: string, created: Carbon, underwriting: ?Carbon, decision: ?Carbon, disbursal: ?Carbon, completion: ?Carbon}  $plan
     */
    protected function seedCustomerFollowUps(Customer $customer, Employee $caller, User $callerUser, array $plan): void
    {
        if (! in_array($customer->journey_status, ['not_started', 'sfl', 'underwriting', 'approved', 'carry_forward'], true)) {
            return;
        }

        if ($caller->exit_status === 'yes') {
            return;
        }

        $today = $this->realNow()->copy()->startOfDay();
        $firstContact = $plan['created']->copy()->addDay()->min($today);

        $this->replay($this->momentOn($firstContact, 12, fake()->numberBetween(0, 59)), $callerUser, fn () => FollowUp::query()->create([
            'customer_id' => $customer->id,
            'employee_id' => $caller->id,
            'follow_up_type' => fake()->randomElement(['Call', 'WhatsApp']),
            'status' => 'Journey Started',
            'remarks' => 'Shared the document checklist with the customer.',
            'next_follow_up_date' => $firstContact->copy()->addDays(2)->setTime(11, 0),
        ]));

        $status = match ($customer->journey_status) {
            'not_started' => fake()->randomElement(['On Hold', 'Out of Station']),
            'carry_forward' => 'Delay Multifunding',
            'approved' => fake()->randomElement(['Awaiting Low ROI', 'Awaiting PF Waiver', 'Journey Started']),
            default => 'Journey Started',
        };

        $this->replay($this->momentOn($today, 10, fake()->numberBetween(0, 59)), $callerUser, fn () => FollowUp::query()->create([
            'customer_id' => $customer->id,
            'employee_id' => $caller->id,
            'follow_up_type' => fake()->randomElement(['Call', 'WhatsApp', 'Email', 'Visit']),
            'status' => $status,
            'remarks' => match ($customer->journey_status) {
                'not_started' => 'Customer to share consent once back in town.',
                'sfl' => 'Pending documents promised by the customer.',
                'underwriting' => 'Credit asked for the latest salary slip.',
                'approved' => 'Customer negotiating ROI before signing.',
                default => 'Customer wants disbursal next month.',
            },
            'next_follow_up_date' => $today->copy()->addDays(fake()->numberBetween(0, 7))->setTime(fake()->randomElement([10, 11, 12, 14, 15, 16, 17]), fake()->randomElement([0, 30])),
        ]));
    }

    /**
     * A few files Team Leaders and Managers source themselves ("direct"
     * customers, see CreateCustomer::isDirectCustomer), so their own
     * books are not empty.
     */
    protected function seedDirectCustomers(): void
    {
        $owners = Employee::query()
            ->whereIn('designation', [Employee::DESIGNATION_TEAM_LEADER, Employee::DESIGNATION_MANAGER])
            ->orderBy('id')
            ->get();

        foreach ($owners as $owner) {
            $ownerUser = $this->loginsByEmployee[$owner->id];
            $seniorUser = $this->seniorFor($owner);
            $createdOn = $this->loggingDay($owner, fake()->numberBetween(1, 2)) ?? $this->realNow()->copy()->subDays(20)->startOfDay();
            $plan = $this->planTimeline($createdOn, fake()->randomElement(['sanctioned', 'completed', 'approved']));
            $profile = $this->customerProfile($plan['outcome']);

            $customer = $this->replay($this->momentOn($createdOn, 13, 20), $ownerUser, fn (): Customer => Customer::query()->create([
                ...$profile,
                'employee_id' => $owner->id,
                'assign_to' => $owner->id,
                'direct' => true,
                'journey_status' => 'sfl',
            ]));

            $this->walkJourney($customer, $plan, $ownerUser, $seniorUser);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerProfile(string $outcome): array
    {
        $name = $this->nextName();
        $salary = fake()->randomElement([32000, 38000, 45000, 52000, 60000, 72000, 85000, 98000, 120000, 150000, 185000, 240000]);
        $eligibleAmount = min(max($this->roundTo($salary * fake()->numberBetween(12, 22), 10000), 200000), 4000000);
        $city = $this->cityNames->random();
        $otherBank = fake()->boolean(25);
        $bank = $otherBank
            ? fake()->randomElement(self::OTHER_BANKS)
            : fake()->randomElement($this->weightedPool(self::IN_HOUSE_BANKS));

        $profile = [
            'customer_name' => $name,
            'mobile_no' => DemoSeedState::nextMobile(),
            'email' => str_replace(' ', '.', strtolower($name)).'@'.DemoSeedState::EMAIL_DOMAIN,
            'pan_number' => DemoSeedState::nextPan(),
            'current_location' => $city,
            'job_location' => $city,
            'residence_location' => fake()->boolean(80) ? $city : $this->cityNames->random(),
            'salary' => $salary,
            'company_category' => fake()->randomElement(self::COMPANIES),
            'loan_applied' => fake()->boolean(90) ? 'personal_loan' : 'business_loan',
            'eligibility_status' => 'eligible',
            'bank_eligible_for' => $bank,
            'eligible_loan_amount' => $eligibleAmount,
            'lan_no' => 'DEMOLAN'.fake()->unique()->numerify('########'),
            'documentation_status' => 'complete',
            'pending_document' => null,
            'sfl_remarks' => 'Salaried at '.$this->lakhs($salary).' p.m., eligible for '.$this->lakhs($eligibleAmount).' with '.$bank.'.',
        ];

        if ($outcome === 'not_started') {
            $status = fake()->randomElement(['not_eligible', 'not_eligible', 'consent_pending']);
            $reason = $status === 'not_eligible' ? fake()->randomElement(array_keys(self::NOT_ELIGIBLE_REASONS)) : null;

            return [
                ...$profile,
                'eligibility_status' => $status,
                'eligibility_reason' => $reason,
                'bank_eligible_for' => null,
                'eligible_loan_amount' => null,
                'lan_no' => null,
                'documentation_status' => null,
                'sfl_remarks' => $reason ? self::NOT_ELIGIBLE_REASONS[$reason] : 'Awaiting customer consent for bureau pull.',
            ];
        }

        if ($outcome === 'sfl' && fake()->boolean(50)) {
            $profile['documentation_status'] = 'pending';
            $profile['pending_document'] = fake()->randomElements(['bank_statement', 'payslip', 'current_address_proof', 'form_26as', 'photo'], fake()->numberBetween(1, 3));
        }

        return $profile;
    }

    protected function otherBankRemark(Customer $customer, OtherBankRemarkStage $stage, Carbon $day, string $remark): void
    {
        if ($this->otherBankUsers->isEmpty()) {
            return;
        }

        $user = $this->otherBankUsers->random();

        $this->replay($this->momentOn($day, 18, fake()->numberBetween(0, 40)), $user, fn () => OtherBankSupportRemark::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'stage' => $stage,
            'remark' => $remark,
        ]));
    }

    /**
     * The login of the nearest seat above $employee that decides credit —
     * Manager, else Cluster Manager, else Business Head.
     */
    protected function seniorFor(Employee $employee): User
    {
        foreach (['manager_id', 'cluster_id', 'business_head_id'] as $column) {
            if ($employee->{$column} && isset($this->loginsByEmployee[$employee->{$column}])) {
                return $this->loginsByEmployee[$employee->{$column}];
            }
        }

        return $this->loginsByEmployee[$employee->id];
    }

    protected function loginFor(?int $employeeId): ?User
    {
        return $employeeId ? ($this->loginsByEmployee[$employeeId] ?? null) : null;
    }

    protected function nextWorkingDay(Carbon $day): Carbon
    {
        return $this->isWorkingDay($day) ? $day : $day->copy()->addDay();
    }

    protected function nextName(): string
    {
        $index = $this->nameCursor++;
        $first = self::FIRST_NAMES[$index % count(self::FIRST_NAMES)];
        $last = self::LAST_NAMES[intdiv($index, count(self::FIRST_NAMES)) % count(self::LAST_NAMES)];

        return $first.' '.$last;
    }

    /**
     * @param  array<string, int>  $weights
     * @return list<string>
     */
    protected function weightedPool(array $weights): array
    {
        $pool = [];

        foreach ($weights as $value => $weight) {
            array_push($pool, ...array_fill(0, max($weight, 0), $value));
        }

        return $pool;
    }

    protected function roundTo(float $value, int $step): int
    {
        return (int) (round($value / $step) * $step);
    }

    protected function lakhs(float $amount): string
    {
        return '₹'.rtrim(rtrim(number_format($amount / 100000, 2), '0'), '.').'L';
    }
}
