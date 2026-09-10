<?php

namespace Database\Seeders\Demo;

use App\Models\Demo\DemoApplication;
use App\Models\Demo\DemoBank;
use App\Models\Demo\DemoCustomer;
use App\Models\Demo\DemoEmployee;
use App\Models\Demo\DemoFollowUp;
use App\Models\Demo\DemoLead;
use App\Models\Demo\DemoLoanProduct;
use App\Models\Tenant;
use App\Support\Portal\IndianFaker;
use Database\Seeders\Training\TrainingContentSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The sandbox dataset a salesperson opens cold.
 *
 * The important property is that the records are CONNECTED, not merely
 * numerous: a lead is worked by an employee, converts into a customer,
 * that customer has applications with a specific lender and product,
 * and disbursed applications carry dates that fall after their sanction
 * dates. Disconnected random rows would look plausible in a table and
 * fall apart the moment a prospect clicks into one.
 *
 * Also seeds the demo tenant's own copy of the training curriculum, so
 * the sandbox's Training page has content of its own and never reads
 * anything a FynnEdge trainer authored.
 */
class DemoDataSeeder extends Seeder
{
    /*
     * Volumes.
     *
     * The brief set floors (50 leads, 30 customers, 10 applications) and
     * separately asked for a dashboard that reads like a real book —
     * thousands of leads, crores disbursed. Those pull in opposite
     * directions, so the dataset is sized well above the floors: enough
     * that the charts have shape and the headline figures are worth
     * showing on a sales call, still small enough that `demo:reset`
     * finishes in a few seconds between prospects.
     */
    public const LEAD_COUNT = 250;

    public const CUSTOMER_COUNT = 150;

    /** @var list<string> */
    public const MANAGER_NAMES = ['Vikram Nair', 'Kavita Reddy'];

    /** @var list<string> */
    public const TEAM_LEADER_NAMES = ['Rahul Sharma', 'Amit Verma', 'Neha Singh'];

    /** @var list<string> */
    public const EXECUTIVE_NAMES = [
        'Priya Mehta', 'Rohit Kumar', 'Anjali Gupta', 'Vivek Yadav', 'Pooja Sharma',
        'Karan Malhotra', 'Sneha Verma', 'Rohan Kapoor', 'Divya Joshi', 'Manish Bansal',
        'Shreya Iyer', 'Nikhil Chauhan', 'Ritu Agarwal', 'Sanjay Reddy', 'Meera Nair',
    ];

    public function run(): void
    {
        $this->seedFor(Tenant::demo());
    }

    /**
     * Seed (or re-seed) one demo tenant. Called by DemoResetService
     * after it has cleared the tenant's rows.
     */
    public function seedFor(Tenant $tenant): void
    {
        $banks = $this->seedBanks($tenant);
        $products = $this->seedProducts($tenant);
        $employees = $this->seedEmployees($tenant);

        $executives = $employees->where('designation', 'Sales Executive')->values();

        $leads = $this->seedLeads($tenant, $executives, $banks, $products);
        $customers = $this->seedCustomers($tenant, $leads, $executives);
        $this->seedApplications($tenant, $customers, $banks, $products);
        $this->seedFollowUps($tenant, $leads, $customers, $executives);

        // The demo tenant gets its own training content so the sandbox's
        // Training page never reads production courses.
        app(TrainingContentSeeder::class)->seedFor($tenant);
    }

    /**
     * @return Collection<int, DemoBank>
     */
    protected function seedBanks(Tenant $tenant): Collection
    {
        return collect(IndianFaker::BANKS)->map(function (array $bank) use ($tenant): DemoBank {
            $minRate = fake()->randomFloat(2, 9.5, 12.5);

            return DemoBank::create([
                'tenant_id' => $tenant->getKey(),
                'name' => $bank['name'],
                'short_name' => $bank['short'],
                'type' => $bank['type'],
                'min_interest_rate' => $minRate,
                'max_interest_rate' => $minRate + fake()->randomFloat(2, 2, 6),
                'min_salary' => fake()->randomElement([15000, 20000, 25000, 30000, 40000]),
                'payout_rate' => fake()->randomFloat(2, 1.5, 4.5),
                'is_active' => true,
            ]);
        });
    }

    /**
     * @return Collection<int, DemoLoanProduct>
     */
    protected function seedProducts(Tenant $tenant): Collection
    {
        $definitions = [
            ['Personal Loan', 'PL', 100000, 4000000, 12, 60, 10.49],
            ['Home Loan', 'HL', 1000000, 50000000, 60, 300, 8.35],
            ['Business Loan', 'BL', 300000, 7500000, 12, 48, 13.75],
            ['Loan Against Property', 'LAP', 1000000, 30000000, 60, 180, 9.25],
            ['Auto Loan', 'AL', 150000, 3500000, 12, 84, 9.85],
        ];

        return collect($definitions)->map(fn (array $definition): DemoLoanProduct => DemoLoanProduct::create([
            'tenant_id' => $tenant->getKey(),
            'name' => $definition[0],
            'code' => $definition[1],
            'description' => "{$definition[0]} offered across the partner lender panel.",
            'min_amount' => $definition[2],
            'max_amount' => $definition[3],
            'min_tenure_months' => $definition[4],
            'max_tenure_months' => $definition[5],
            'interest_rate_from' => $definition[6],
            'is_active' => true,
        ]));
    }

    /**
     * Builds a real reporting line — executives report to team leaders,
     * team leaders to managers — so the org chart in the demo is not a
     * flat list of names.
     *
     * @return Collection<int, DemoEmployee>
     */
    protected function seedEmployees(Tenant $tenant): Collection
    {
        $sequence = 10001;

        $managers = collect(self::MANAGER_NAMES)->map(fn (string $name): DemoEmployee => $this->employee(
            $tenant, $name, 'Manager', $sequence++, null, 25000000,
        ));

        $teamLeaders = collect(self::TEAM_LEADER_NAMES)->map(fn (string $name, int $index): DemoEmployee => $this->employee(
            $tenant, $name, 'Team Leader', $sequence++, $managers[$index % $managers->count()]->id, 12000000,
        ));

        $executives = collect(self::EXECUTIVE_NAMES)->map(fn (string $name, int $index): DemoEmployee => $this->employee(
            $tenant, $name, 'Sales Executive', $sequence++, $teamLeaders[$index % $teamLeaders->count()]->id, 4000000,
        ));

        return $managers->concat($teamLeaders)->concat($executives);
    }

    protected function employee(
        Tenant $tenant,
        string $name,
        string $designation,
        int $sequence,
        ?int $reportsTo,
        int $target,
    ): DemoEmployee {
        return DemoEmployee::create([
            'tenant_id' => $tenant->getKey(),
            'emp_code' => 'FE'.$sequence,
            'name' => $name,
            'email' => IndianFaker::email($name),
            'mobile_no' => IndianFaker::mobile(),
            'designation' => $designation,
            'department' => 'Sales',
            'city' => IndianFaker::city(),
            'reports_to' => $reportsTo,
            'joined_on' => fake()->dateTimeBetween('-3 years', '-2 months'),
            'monthly_target' => $target,
            'is_active' => true,
        ]);
    }

    /**
     * @param  Collection<int, DemoEmployee>  $executives
     * @param  Collection<int, DemoBank>  $banks
     * @param  Collection<int, DemoLoanProduct>  $products
     * @return Collection<int, DemoLead>
     */
    protected function seedLeads(Tenant $tenant, Collection $executives, Collection $banks, Collection $products): Collection
    {
        /*
         * Status weights, not a uniform random pick: a real pipeline is
         * bottom-heavy, and a demo where a third of leads have converted
         * reads as fake to anyone who sells for a living.
         */
        $statusPool = array_merge(
            array_fill(0, 10, 'new'),
            array_fill(0, 8, 'contacted'),
            array_fill(0, 20, 'qualified'),
            array_fill(0, 10, 'converted'),
            array_fill(0, 1, 'not_interested'),
            array_fill(0, 1, 'invalid'),
        );

        return collect(range(1, self::LEAD_COUNT))->map(function (int $index) use ($tenant, $executives, $banks, $products, $statusPool): DemoLead {
            $name = IndianFaker::name();
            $salary = fake()->numberBetween(25000, 250000);
            $status = $statusPool[($index - 1) % count($statusPool)];

            return DemoLead::create([
                'tenant_id' => $tenant->getKey(),
                'demo_employee_id' => $executives->random()->id,
                'demo_bank_id' => $banks->random()->id,
                'demo_loan_product_id' => $products->random()->id,
                'lead_code' => 'LD'.str_pad((string) (100000 + $index), 6, '0', STR_PAD_LEFT),
                'customer_name' => $name,
                'mobile_no' => IndianFaker::mobile(),
                'email' => IndianFaker::email($name),
                'pan_number' => IndianFaker::pan(),
                'city' => IndianFaker::city(),
                'source' => fake()->randomElement(IndianFaker::LEAD_SOURCES),
                'salary' => $salary,
                'requested_amount' => $salary * fake()->numberBetween(8, 20),
                'status' => $status,
                'follow_up_date' => fake()->dateTimeBetween('-10 days', '+15 days'),
                'remarks' => 'Sandbox record — no real customer is represented here.',
                'is_converted' => $status === 'converted',
                'created_at' => fake()->dateTimeBetween('-6 months', 'now'),
            ]);
        });
    }

    /**
     * Thirty customers: every converted lead becomes one, and the
     * remainder are drawn from qualified leads, so no customer exists
     * without a lead behind it.
     *
     * @param  Collection<int, DemoLead>  $leads
     * @param  Collection<int, DemoEmployee>  $executives
     * @return Collection<int, DemoCustomer>
     */
    protected function seedCustomers(Tenant $tenant, Collection $leads, Collection $executives): Collection
    {
        $sourceLeads = $leads->where('status', 'converted')
            ->concat($leads->where('status', 'qualified'))
            ->take(self::CUSTOMER_COUNT)
            ->values();

        $journeyPool = array_merge(
            array_fill(0, 12, 'otp'),
            array_fill(0, 14, 'sfl'),
            array_fill(0, 16, 'underwriting'),
            array_fill(0, 14, 'approved'),
            array_fill(0, 12, 'sanctioned'),
            array_fill(0, 26, 'disbursed'),
            array_fill(0, 6, 'rejected'),
        );

        return $sourceLeads->map(function (DemoLead $lead, int $index) use ($tenant, $journeyPool): DemoCustomer {
            $journey = $journeyPool[$index % count($journeyPool)];

            $customer = DemoCustomer::create([
                'tenant_id' => $tenant->getKey(),
                'demo_lead_id' => $lead->id,
                'demo_employee_id' => $lead->demo_employee_id,
                'customer_code' => 'CU'.str_pad((string) (200000 + $index), 6, '0', STR_PAD_LEFT),
                'customer_name' => $lead->customer_name,
                'mobile_no' => $lead->mobile_no,
                'email' => $lead->email,
                'pan_number' => $lead->pan_number,
                'city' => $lead->city,
                'company_name' => fake()->randomElement(IndianFaker::COMPANIES),
                'company_category' => fake()->randomElement(IndianFaker::COMPANY_CATEGORIES),
                'salary' => $lead->salary,
                'eligible_loan_amount' => $lead->salary * fake()->numberBetween(10, 24),
                'journey_status' => $journey,
                'eligibility_status' => $journey === 'rejected' ? 'not_eligible' : 'eligible',
                'created_at' => $lead->created_at,
            ]);

            $lead->forceFill([
                'status' => 'converted',
                'is_converted' => true,
            ])->save();

            return $customer;
        });
    }

    /**
     * One application per customer that has reached at least the
     * underwriting rung, with the application's status derived from the
     * customer's journey status so the two never contradict each other.
     *
     * @param  Collection<int, DemoCustomer>  $customers
     * @param  Collection<int, DemoBank>  $banks
     * @param  Collection<int, DemoLoanProduct>  $products
     */
    protected function seedApplications(Tenant $tenant, Collection $customers, Collection $banks, Collection $products): void
    {
        $eligible = $customers->whereIn('journey_status', [
            'underwriting', 'approved', 'sanctioned', 'disbursed', 'rejected',
        ])->values();

        foreach ($eligible as $index => $customer) {
            $bank = $banks->random();
            $product = $products->random();
            $applied = (int) min(
                $product->max_amount,
                max($product->min_amount, $customer->eligible_loan_amount),
            );

            $appliedOn = Carbon::parse($customer->created_at)->addDays(fake()->numberBetween(2, 12));

            $status = match ($customer->journey_status) {
                'disbursed' => 'disbursed',
                'sanctioned', 'approved' => 'sanctioned',
                'rejected' => 'rejected',
                default => 'under_review',
            };

            $sanctioned = in_array($status, ['sanctioned', 'disbursed'], true)
                ? (int) round($applied * fake()->randomFloat(2, 0.6, 1.0))
                : 0;

            $sanctionedOn = $sanctioned > 0
                ? $appliedOn->copy()->addDays(fake()->numberBetween(5, 20))
                : null;

            $disbursedOn = $status === 'disbursed' && $sanctionedOn !== null
                ? $sanctionedOn->copy()->addDays(fake()->numberBetween(2, 12))
                : null;

            DemoApplication::create([
                'tenant_id' => $tenant->getKey(),
                'demo_customer_id' => $customer->id,
                'demo_bank_id' => $bank->id,
                'demo_loan_product_id' => $product->id,
                'demo_employee_id' => $customer->demo_employee_id,
                'application_no' => 'APP'.str_pad((string) (300000 + $index), 6, '0', STR_PAD_LEFT),
                'lan_no' => $sanctioned > 0 ? 'LAN'.fake()->numerify('#######') : null,
                'applied_amount' => $applied,
                'sanctioned_amount' => $sanctioned,
                'disbursed_amount' => $status === 'disbursed' ? $sanctioned : 0,
                'interest_rate' => fake()->randomFloat(2, (float) $bank->min_interest_rate, (float) $bank->max_interest_rate),
                'tenure_months' => fake()->randomElement([12, 24, 36, 48, 60]),
                'status' => $status,
                'applied_on' => $appliedOn->toDateString(),
                'sanctioned_on' => $sanctionedOn?->toDateString(),
                'disbursed_on' => $disbursedOn?->toDateString(),
                'payout_rate' => $bank->payout_rate,
                'remarks' => $status === 'rejected'
                    ? fake()->randomElement([
                        'Income documents insufficient',
                        'Existing obligations too high',
                        'Employer not in approved category',
                    ])
                    : null,
                'created_at' => $appliedOn,
            ]);

            /*
             * Roughly every third case is taken to a second lender —
             * either because the first rejected it or because the
             * customer wanted a better rate. Without this the demo book
             * shows a strict one-application-per-customer pattern that
             * nobody who actually distributes loans would recognise.
             */
            if ($index % 3 === 0) {
                $secondBank = $banks->where('id', '!=', $bank->id)->random();
                $secondStatus = $status === 'rejected' ? 'sanctioned' : 'under_review';
                $secondSanctioned = $secondStatus === 'sanctioned'
                    ? (int) round($applied * fake()->randomFloat(2, 0.5, 0.9))
                    : 0;
                $secondAppliedOn = $appliedOn->copy()->addDays(fake()->numberBetween(10, 25));

                DemoApplication::create([
                    'tenant_id' => $tenant->getKey(),
                    'demo_customer_id' => $customer->id,
                    'demo_bank_id' => $secondBank->id,
                    'demo_loan_product_id' => $product->id,
                    'demo_employee_id' => $customer->demo_employee_id,
                    'application_no' => 'APP'.str_pad((string) (350000 + $index), 6, '0', STR_PAD_LEFT),
                    'lan_no' => $secondSanctioned > 0 ? 'LAN'.fake()->numerify('#######') : null,
                    'applied_amount' => $applied,
                    'sanctioned_amount' => $secondSanctioned,
                    'disbursed_amount' => 0,
                    'interest_rate' => fake()->randomFloat(2, (float) $secondBank->min_interest_rate, (float) $secondBank->max_interest_rate),
                    'tenure_months' => fake()->randomElement([12, 24, 36, 48, 60]),
                    'status' => $secondStatus,
                    'applied_on' => $secondAppliedOn->toDateString(),
                    'sanctioned_on' => $secondSanctioned > 0
                        ? $secondAppliedOn->copy()->addDays(fake()->numberBetween(4, 15))->toDateString()
                        : null,
                    'disbursed_on' => null,
                    'payout_rate' => $secondBank->payout_rate,
                    'remarks' => $status === 'rejected' ? 'Re-logged with an alternate lender.' : null,
                    'created_at' => $secondAppliedOn,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, DemoLead>  $leads
     * @param  Collection<int, DemoCustomer>  $customers
     * @param  Collection<int, DemoEmployee>  $executives
     */
    protected function seedFollowUps(Tenant $tenant, Collection $leads, Collection $customers, Collection $executives): void
    {
        foreach ($leads->take(120) as $lead) {
            DemoFollowUp::create([
                'tenant_id' => $tenant->getKey(),
                'demo_lead_id' => $lead->id,
                'demo_employee_id' => $lead->demo_employee_id,
                'type' => fake()->randomElement(['call', 'whatsapp', 'email']),
                'scheduled_at' => fake()->dateTimeBetween('-10 days', '+14 days'),
                'status' => fake()->randomElement(['pending', 'completed', 'missed']),
                'outcome' => fake()->randomElement([null, 'interested', 'call back later', 'not reachable']),
                'remarks' => 'Sandbox follow-up.',
            ]);
        }

        foreach ($customers->take(90) as $customer) {
            DemoFollowUp::create([
                'tenant_id' => $tenant->getKey(),
                'demo_customer_id' => $customer->id,
                'demo_employee_id' => $customer->demo_employee_id,
                'type' => fake()->randomElement(['call', 'visit', 'whatsapp']),
                'scheduled_at' => fake()->dateTimeBetween('-7 days', '+10 days'),
                'status' => fake()->randomElement(['pending', 'completed']),
                'outcome' => fake()->randomElement([null, 'documents collected', 'awaiting bank']),
                'remarks' => 'Sandbox follow-up.',
            ]);
        }
    }
}
