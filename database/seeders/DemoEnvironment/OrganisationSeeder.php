<?php

namespace Database\Seeders\DemoEnvironment;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Roles, banks, cities and the whole reporting tree: two business lines,
 * three clusters, six managers, twelve teams of callers — plus the
 * support desks (MIS, Accounts, IT, Other Bank Support) and one login
 * per demo persona.
 */
class OrganisationSeeder extends Seeder
{
    private DemoWorld $world;

    private string $password;

    /**
     * The persona people get fixed, readable names so the presenter can
     * talk about "Priya's team" across a whole walkthrough.
     *
     * @var array<string, string>
     */
    private const PERSONA_NAMES = [
        'admin' => 'Neha Kapoor',
        'business-head' => 'Vikram Malhotra',
        'cluster-manager' => 'Sanjay Verma',
        'manager' => 'Priya Nair',
        'team-leader' => 'Rohan Gupta',
        'caller' => 'Ananya Singh',
        'accounts' => 'Meera Iyer',
        'mis' => 'Karan Joshi',
        'other-bank-support' => 'Farhan Qureshi',
        'it' => 'Arjun Rao',
    ];

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> city, state, state code, city code */
    private const CITIES = [
        ['New Delhi', 'Delhi', 'DL', 'DEL'], ['Noida', 'Uttar Pradesh', 'UP', 'NOI'], ['Greater Noida', 'Uttar Pradesh', 'UP', 'GNO'],
        ['Ghaziabad', 'Uttar Pradesh', 'UP', 'GZB'], ['Gurugram', 'Haryana', 'HR', 'GGN'], ['Faridabad', 'Haryana', 'HR', 'FBD'],
        ['Lucknow', 'Uttar Pradesh', 'UP', 'LKO'], ['Kanpur', 'Uttar Pradesh', 'UP', 'KNP'], ['Agra', 'Uttar Pradesh', 'UP', 'AGR'],
        ['Varanasi', 'Uttar Pradesh', 'UP', 'VNS'], ['Dehradun', 'Uttarakhand', 'UK', 'DDN'], ['Chandigarh', 'Chandigarh', 'CH', 'CHD'],
        ['Jaipur', 'Rajasthan', 'RJ', 'JAI'], ['Udaipur', 'Rajasthan', 'RJ', 'UDR'], ['Mumbai', 'Maharashtra', 'MH', 'MUM'],
        ['Navi Mumbai', 'Maharashtra', 'MH', 'NMB'], ['Thane', 'Maharashtra', 'MH', 'THN'], ['Pune', 'Maharashtra', 'MH', 'PUN'],
        ['Nagpur', 'Maharashtra', 'MH', 'NAG'], ['Nashik', 'Maharashtra', 'MH', 'NSK'], ['Ahmedabad', 'Gujarat', 'GJ', 'AMD'],
        ['Surat', 'Gujarat', 'GJ', 'SUR'], ['Vadodara', 'Gujarat', 'GJ', 'VAD'], ['Indore', 'Madhya Pradesh', 'MP', 'IDR'],
        ['Bhopal', 'Madhya Pradesh', 'MP', 'BHO'], ['Bengaluru', 'Karnataka', 'KA', 'BLR'], ['Mysuru', 'Karnataka', 'KA', 'MYS'],
        ['Hyderabad', 'Telangana', 'TG', 'HYD'], ['Chennai', 'Tamil Nadu', 'TN', 'CHE'], ['Coimbatore', 'Tamil Nadu', 'TN', 'CBE'],
        ['Kochi', 'Kerala', 'KL', 'KOC'], ['Thiruvananthapuram', 'Kerala', 'KL', 'TVM'], ['Kolkata', 'West Bengal', 'WB', 'KOL'],
        ['Bhubaneswar', 'Odisha', 'OD', 'BBS'], ['Patna', 'Bihar', 'BR', 'PAT'], ['Ranchi', 'Jharkhand', 'JH', 'RAN'],
        ['Guwahati', 'Assam', 'AS', 'GAU'], ['Ludhiana', 'Punjab', 'PB', 'LDH'], ['Amritsar', 'Punjab', 'PB', 'ATQ'],
        ['Visakhapatnam', 'Andhra Pradesh', 'AP', 'VSK'],
    ];

    private const BANKS = [
        'BFL Prime', 'BFL Growth', 'BFL SOL', 'BFL RSL', 'ABFL', 'Incred', 'Fibe', 'Poonawala', 'Finnable', 'Tata Capital',
        'Piramal Finance', 'HDFC Bank', 'ICICI Bank', 'Axis Bank', 'State Bank of India', 'Kotak Mahindra Bank',
        'IndusInd Bank', 'Yes Bank', 'IDFC First Bank', 'AU Small Finance Bank', 'L&T Finance',
    ];

    private int $empSequence = 1000;

    public function run(DemoWorld $world): void
    {
        $this->world = $world;
        $this->password = Hash::make((string) config('demo.password'));

        $this->call(RolesSeeder::class);
        $this->seedBanks();
        $this->seedCities();
        $this->seedHierarchy();
        $this->seedSupportDesks();
        $this->seedReportingChanges();

        $world->employees = DB::table('employees')->get();
    }

    private function seedBanks(): void
    {
        $now = $this->world->now;

        $this->world->insert('banks', array_map(fn (string $bank): array => [
            'bank_name' => $bank,
            'loan_type' => 'personal_loan',
            'payment_from' => str_starts_with($bank, 'BFL') ? 'Direct' : 'DSA',
            'payout' => $this->world->pick([1.25, 1.5, 1.75, 2.0, 2.25, 2.5]),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::BANKS));

        $this->world->banks = DB::table('banks')->get(['id', 'bank_name'])
            ->map(fn (object $bank): array => ['id' => $bank->id, 'name' => $bank->bank_name])
            ->keyBy('id')
            ->all();
    }

    private function seedCities(): void
    {
        $now = $this->world->now;

        $this->world->insert('cities', array_map(fn (array $city): array => [
            'country' => 'India',
            'state' => $city[1],
            'city' => $city[0],
            'pincode' => (string) mt_rand(110001, 799999),
            'state_code' => $city[2],
            'city_code' => $city[3],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::CITIES));

        $this->world->cities = array_column(self::CITIES, 0);
    }

    /**
     * Business Head → Cluster Manager → Manager → Team Leader → Callers.
     * The first node on every level is the persona, so the persona chain
     * is one continuous line from Vikram down to Ananya.
     */
    private function seedHierarchy(): void
    {
        $lines = [
            ['region' => 'North', 'clusters' => [['NCR', 'Noida'], ['Uttar Pradesh', 'Lucknow']]],
            ['region' => 'West', 'clusters' => [['Maharashtra', 'Pune']]],
        ];

        foreach ($lines as $lineIndex => $line) {
            $businessHead = $this->makeEmployee(
                designation: Employee::DESIGNATION_BUSINESS_HEAD,
                persona: $lineIndex === 0 ? 'business-head' : null,
                joinedMonthsAgo: mt_rand(40, 60),
                position: 'Business Head',
                costCenter: "CC-{$line['region']}",
            );

            foreach ($line['clusters'] as $clusterIndex => [$clusterName, $city]) {
                $clusterPersona = $lineIndex === 0 && $clusterIndex === 0;

                $cluster = $this->makeEmployee(
                    designation: Employee::DESIGNATION_CLUSTER,
                    persona: $clusterPersona ? 'cluster-manager' : null,
                    joinedMonthsAgo: mt_rand(28, 44),
                    position: 'Cluster Head',
                    costCenter: "CC-{$line['region']}-".strtoupper(substr($city, 0, 3)),
                    bosses: ['business_head_id' => $businessHead->id],
                    category: 'cluster_manager',
                );

                $unit = str($cluster->emp_name)->lower()->snake()->toString();

                for ($m = 0; $m < 2; $m++) {
                    $managerPersona = $clusterPersona && $m === 0;

                    $manager = $this->makeEmployee(
                        designation: Employee::DESIGNATION_MANAGER,
                        persona: $managerPersona ? 'manager' : null,
                        joinedMonthsAgo: mt_rand(16, 30),
                        position: 'Manager',
                        costCenter: $cluster->cost_center,
                        unit: $unit,
                        bosses: ['cluster_id' => $cluster->id, 'business_head_id' => $businessHead->id],
                        category: 'manager',
                    );

                    for ($t = 0; $t < 2; $t++) {
                        $teamPersona = $managerPersona && $t === 0;

                        $teamLeader = $this->makeEmployee(
                            designation: Employee::DESIGNATION_TEAM_LEADER,
                            persona: $teamPersona ? 'team-leader' : null,
                            joinedMonthsAgo: mt_rand(10, 22),
                            position: $this->world->pick(['Team Leader', 'Sourcing Leader']),
                            costCenter: $cluster->cost_center,
                            unit: $unit,
                            bosses: ['manager_id' => $manager->id, 'cluster_id' => $cluster->id, 'business_head_id' => $businessHead->id],
                            category: 'team_leader',
                        );

                        $this->seedTeam($teamLeader, $manager, $cluster, $businessHead, $unit, $teamPersona);
                    }
                }
            }
        }
    }

    private function seedTeam(Employee $teamLeader, Employee $manager, Employee $cluster, Employee $businessHead, string $unit, bool $isPersonaTeam): void
    {
        $bosses = [
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'cluster_id' => $cluster->id,
            'business_head_id' => $businessHead->id,
        ];

        for ($c = 0; $c < 5; $c++) {
            $this->makeEmployee(
                designation: Employee::DESIGNATION_CALLER,
                persona: $isPersonaTeam && $c === 0 ? 'caller' : null,
                joinedMonthsAgo: mt_rand(3, 26),
                position: $this->world->pick(['Sourcing Specialist', 'caller', 'Sourcing Specialist']),
                costCenter: $cluster->cost_center,
                unit: $unit,
                bosses: $bosses,
                category: $isPersonaTeam && $c === 0 ? '3000000' : $this->world->weighted(['2500000' => 50, '3000000' => 35, '3500000' => 15]),
            );
        }

        // Churn: roughly every other team lost a caller in the last two
        // months, and a third of teams took on a new joiner this month.
        if (! $isPersonaTeam && $this->world->chance(0.5)) {
            $exitDate = $this->world->monthStart(mt_rand(0, 1))->addDays(mt_rand(3, 24));

            if ($exitDate->greaterThanOrEqualTo($this->world->today)) {
                $exitDate = $this->world->today->copy()->subDays(mt_rand(2, 6));
            }

            $this->makeEmployee(
                designation: Employee::DESIGNATION_CALLER,
                persona: null,
                joinedMonthsAgo: mt_rand(4, 14),
                position: 'Sourcing Specialist',
                costCenter: $cluster->cost_center,
                unit: $unit,
                bosses: $bosses,
                category: '2500000',
                exitDate: $exitDate,
            );
        }

        if ($isPersonaTeam || $this->world->chance(0.33)) {
            $joined = $this->world->today->copy()->subDays(mt_rand(3, 16));

            $this->makeEmployee(
                designation: Employee::DESIGNATION_CALLER,
                persona: null,
                joinedMonthsAgo: 0,
                position: 'Sourcing Specialist',
                costCenter: $cluster->cost_center,
                unit: $unit,
                bosses: $bosses,
                category: '2500000',
                joinedOn: $joined->lessThan($this->world->monthStart()) ? $this->world->monthStart() : $joined,
            );
        }
    }

    /**
     * MIS, Accounts and IT sit outside the sales tree (designation 1, as
     * the live company records its back-office staff); Other Bank Support
     * has its own designation and reports to nobody.
     */
    private function seedSupportDesks(): void
    {
        $this->makeUser('admin', self::PERSONA_NAMES['admin'], null, 'Admin');

        foreach (['accounts' => 'Accounts', 'mis' => 'MIS', 'it' => 'IT'] as $persona => $role) {
            $this->makeEmployee(
                designation: Employee::DESIGNATION_ADMIN,
                persona: $persona,
                joinedMonthsAgo: mt_rand(12, 36),
                position: $role === 'IT' ? 'IT Administrator' : "{$role} Executive",
                costCenter: 'CC-HO',
                role: $role,
            );
        }

        $this->makeEmployee(
            designation: Employee::DESIGNATION_OTHER_BANK_SUPPORT,
            persona: 'other-bank-support',
            joinedMonthsAgo: mt_rand(8, 20),
            position: 'Other Bank Support',
            costCenter: 'CC-HO',
            role: 'Other Bank Support',
        );

        $this->makeEmployee(
            designation: Employee::DESIGNATION_OTHER_BANK_SUPPORT,
            persona: null,
            joinedMonthsAgo: mt_rand(3, 10),
            position: 'Other Bank Support',
            costCenter: 'CC-HO',
            role: 'Other Bank Support',
        );
    }

    /**
     * A handful of historic moves, so the reporting history and the
     * hierarchy transfer log are not just a column of "joining" rows.
     */
    private function seedReportingChanges(): void
    {
        $adminUserId = $this->world->personaUserIds['admin'];
        $callers = Employee::query()
            ->where('designation', Employee::DESIGNATION_CALLER)
            ->where('exit_status', 'no')
            ->whereDate('doj', '<', $this->world->monthStart(3))
            ->inRandomOrder()
            ->limit(6)
            ->get();

        $teamLeaders = Employee::query()->where('designation', Employee::DESIGNATION_TEAM_LEADER)->get();

        foreach ($callers as $caller) {
            $formerLeader = $teamLeaders->where('id', '!=', $caller->superviser_id)->random();
            $movedOn = $this->world->monthStart(mt_rand(2, 3))->addDays(mt_rand(0, 20));

            DB::table('employee_reporting_history')
                ->where('employee_id', $caller->id)
                ->update([
                    'new_superviser_id' => $formerLeader->id,
                    'new_manager_id' => $formerLeader->manager_id,
                    'effective_to' => $movedOn->copy()->subDay()->toDateString(),
                ]);

            DB::table('employee_reporting_history')->insert([
                'employee_id' => $caller->id,
                'old_superviser_id' => $formerLeader->id,
                'old_manager_id' => $formerLeader->manager_id,
                'old_cluster_id' => $formerLeader->cluster_id,
                'old_business_head_id' => $formerLeader->business_head_id,
                'new_superviser_id' => $caller->superviser_id,
                'new_manager_id' => $caller->manager_id,
                'new_cluster_id' => $caller->cluster_id,
                'new_business_head_id' => $caller->business_head_id,
                'effective_date' => $movedOn->toDateString(),
                'change_type' => 'reporting_change',
                'updated_by' => $adminUserId,
                'remarks' => $this->world->pick([
                    'Team rebalanced after new joiners.',
                    'Moved to a smaller team for coaching.',
                    'Shift change — moved to the evening team.',
                ]),
                'created_at' => $movedOn,
                'updated_at' => $movedOn,
            ]);

            $caller->forceFill(['reporting_date' => $movedOn->toDateString()])->saveQuietly();
        }

        $clusters = Employee::query()->where('designation', Employee::DESIGNATION_CLUSTER)->orderBy('id')->get();
        $moved = $callers->take(3)->pluck('id')->all();

        DB::table('hierarchy_transfer_logs')->insert([
            'source_cluster_manager_id' => $clusters[1]->id,
            'target_cluster_manager_id' => $clusters[0]->id,
            'transfer_type' => 'flexible_reassignment',
            'selected_employee_ids' => json_encode($moved),
            'affected_employee_ids' => json_encode($moved),
            'affected_count' => count($moved),
            'effective_date' => $this->world->monthStart(2)->addDays(4)->toDateString(),
            'performed_by' => $adminUserId,
            'remarks' => 'Rebalanced callers between the NCR and Uttar Pradesh clusters.',
            'created_at' => $this->world->monthStart(2)->addDays(4),
            'updated_at' => $this->world->monthStart(2)->addDays(4),
        ]);
    }

    /**
     * @param  array<string, int>  $bosses
     */
    private function makeEmployee(
        int $designation,
        ?string $persona,
        int $joinedMonthsAgo,
        string $position,
        string $costCenter,
        ?string $unit = null,
        array $bosses = [],
        ?string $category = null,
        ?string $role = null,
        ?Carbon $exitDate = null,
        ?Carbon $joinedOn = null,
    ): Employee {
        $name = $persona ? self::PERSONA_NAMES[$persona] : $this->world->faker->firstName().' '.$this->world->faker->lastName();
        $email = $persona
            ? config("demo.personas.{$persona}.email")
            : $this->world->email($name, 'fynnon-demo.test');

        $doj = $joinedOn ?? $this->world->today->copy()->subMonthsNoOverflow($joinedMonthsAgo)->subDays(mt_rand(0, 25));

        $employee = new Employee([
            'emp_id' => 'FYN'.(++$this->empSequence),
            'emp_name' => $name,
            'email' => $email,
            'designation' => $designation,
            'doj' => $doj->toDateString(),
            'reporting_date' => $doj->toDateString(),
            'superviser_id' => $bosses['superviser_id'] ?? null,
            'manager_id' => $bosses['manager_id'] ?? null,
            'cluster_id' => $bosses['cluster_id'] ?? null,
            'business_head_id' => $bosses['business_head_id'] ?? null,
            'cost_center' => $costCenter,
            'unit_name' => $unit,
            'category' => $category,
            'position' => $position,
            'exit_status' => $exitDate ? 'yes' : 'no',
            'exit_date' => $exitDate?->toDateString(),
        ]);
        $employee->forceFill(['created_at' => $doj, 'updated_at' => $doj])->save();

        $roleName = $role ?? match ($designation) {
            Employee::DESIGNATION_BUSINESS_HEAD => 'Business Head',
            Employee::DESIGNATION_CLUSTER => 'Cluster Manager',
            Employee::DESIGNATION_MANAGER => 'Manager',
            Employee::DESIGNATION_TEAM_LEADER => 'Team Leader',
            default => 'Caller',
        };

        $this->makeUser($persona, $name, $employee, $roleName, $email);

        if ($persona) {
            $this->world->personaEmployeeIds[$persona] = $employee->id;
        }

        return $employee;
    }

    private function makeUser(?string $persona, string $name, ?Employee $employee, string $role, ?string $email = null): void
    {
        $user = new User([
            'name' => $name,
            'email' => $email ?? config("demo.personas.{$persona}.email"),
            'password' => $this->password,
            'is_active' => true,
        ]);
        $user->forceFill([
            'employee_id' => $employee?->id,
            'email_verified_at' => $this->world->now,
            'created_at' => $employee?->created_at ?? $this->world->monthStart(24),
        ])->save();
        $user->assignRole($role);

        if ($employee) {
            $this->world->userIdByEmployee[$employee->id] = $user->id;
        }

        if ($persona) {
            $this->world->personaUserIds[$persona] = $user->id;
        }
    }
}
