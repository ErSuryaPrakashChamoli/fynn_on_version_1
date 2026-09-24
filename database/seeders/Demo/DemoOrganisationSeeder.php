<?php

namespace Database\Seeders\Demo;

use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyRoleService;
use App\Services\OtherBankSupportService;
use App\Services\ReportingLineService;
use Database\Seeders\Demo\Concerns\DemoSeedState;
use Database\Seeders\Demo\Concerns\SeedsDemoTimeline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The demo company: the Admin login and the whole reporting tree, each
 * seat an Employee with a linked login carrying the role that belongs to it.
 *
 * Built top-down so every employee's reporting columns can be derived by
 * ReportingLineService::columnsUnder() from a boss that already exists —
 * one column per level, skipped levels left null (see the skip-level
 * Team Leader under Cluster West and the caller reporting straight to a
 * Manager). Hierarchy roles are the ones HierarchyRoleService::ROLES maps
 * to each designation. Back-office logins (MIS, Accounts, IT) sit on the
 * Admin designation, outside the tree, as they do on the live system;
 * Other Bank Support uses its own out-of-tree designation.
 */
class DemoOrganisationSeeder extends Seeder
{
    use SeedsDemoTimeline;

    /**
     * Seat => [name, designation, boss seat, date of joining, category, position, login e-mail local part|null, exit date|null].
     *
     * @var array<string, array{0: string, 1: int, 2: ?string, 3: string, 4: ?string, 5: string, 6: ?string, 7?: ?string}>
     */
    public const STAFF = [
        'bh' => ['Rajeev Malhotra', Employee::DESIGNATION_BUSINESS_HEAD, null, '2023-04-03', 'business_head', 'Business Head', 'demo.businesshead'],

        'cm_north' => ['Sunita Kapoor', Employee::DESIGNATION_CLUSTER, 'bh', '2023-06-12', 'cluster_manager', 'Cluster Manager — North', 'demo.cluster'],
        'cm_west' => ['Harish Iyer', Employee::DESIGNATION_CLUSTER, 'bh', '2023-09-18', 'cluster_manager', 'Cluster Manager — West', null],

        'mgr_delhi' => ['Vikram Singh', Employee::DESIGNATION_MANAGER, 'cm_north', '2024-01-08', 'manager', 'Sales Manager', 'demo.manager'],
        'mgr_noida' => ['Kavita Joshi', Employee::DESIGNATION_MANAGER, 'cm_north', '2024-03-04', 'manager', 'Sales Manager', null],
        'mgr_mumbai' => ['Arjun Nair', Employee::DESIGNATION_MANAGER, 'cm_west', '2024-02-19', 'manager', 'Sales Manager', null],
        'mgr_pune' => ['Meera Reddy', Employee::DESIGNATION_MANAGER, 'cm_west', '2024-07-01', 'manager', 'Sales Manager', null],

        'tl_delhi_a' => ['Rohit Verma', Employee::DESIGNATION_TEAM_LEADER, 'mgr_delhi', '2024-05-06', 'team_leader', 'Sourcing Leader', 'demo.teamleader'],
        'tl_delhi_b' => ['Neha Bansal', Employee::DESIGNATION_TEAM_LEADER, 'mgr_delhi', '2024-08-12', 'team_leader', 'Sourcing Leader', null],
        'tl_noida' => ['Karan Mehta', Employee::DESIGNATION_TEAM_LEADER, 'mgr_noida', '2024-09-02', 'team_leader', 'Sourcing Leader', null],
        'tl_mumbai' => ['Pooja Agarwal', Employee::DESIGNATION_TEAM_LEADER, 'mgr_mumbai', '2024-06-17', 'team_leader', 'Sourcing Leader', null],
        'tl_pune' => ['Deepak Chauhan', Employee::DESIGNATION_TEAM_LEADER, 'mgr_pune', '2024-11-11', 'team_leader', 'Sourcing Leader', null],
        // Skip-level: reports straight to the Cluster Manager, no Manager above.
        'tl_west_direct' => ['Shreya Gupta', Employee::DESIGNATION_TEAM_LEADER, 'cm_west', '2025-01-20', 'team_leader', 'Sourcing Leader', null],

        'c01' => ['Amit Sharma', Employee::DESIGNATION_CALLER, 'tl_delhi_a', '2025-02-03', '2500000', 'Sourcing Specialist', 'demo.caller'],
        'c02' => ['Priya Yadav', Employee::DESIGNATION_CALLER, 'tl_delhi_a', '2025-03-10', '3000000', 'Sourcing Specialist', null],
        'c03' => ['Sanjay Kumar', Employee::DESIGNATION_CALLER, 'tl_delhi_a', '2025-04-14', '2500000', 'Sourcing Specialist', null],
        'c04' => ['Ritu Malhotra', Employee::DESIGNATION_CALLER, 'tl_delhi_a', '2025-06-02', '2500000', 'Sourcing Specialist', null],
        'c05' => ['Nikhil Joshi', Employee::DESIGNATION_CALLER, 'tl_delhi_b', '2025-01-13', '3500000', 'Senior Sourcing Specialist', null],
        'c06' => ['Divya Singh', Employee::DESIGNATION_CALLER, 'tl_delhi_b', '2025-05-19', '2500000', 'Sourcing Specialist', null],
        // Left mid-August: stays in the data, visible to the hierarchy, out of targets.
        'c07' => ['Manish Gupta', Employee::DESIGNATION_CALLER, 'tl_delhi_b', '2025-02-24', '2500000', 'Sourcing Specialist', null, '2026-08-20'],
        'c08' => ['Anjali Kapoor', Employee::DESIGNATION_CALLER, 'tl_noida', '2025-03-03', '3000000', 'Sourcing Specialist', null],
        'c09' => ['Rahul Chauhan', Employee::DESIGNATION_CALLER, 'tl_noida', '2025-07-07', '2500000', 'Sourcing Specialist', null],
        'c10' => ['Sneha Iyer', Employee::DESIGNATION_CALLER, 'tl_noida', '2025-08-11', '2500000', 'Sourcing Specialist', null],
        'c11' => ['Suresh Nair', Employee::DESIGNATION_CALLER, 'tl_mumbai', '2025-02-10', '3000000', 'Sourcing Specialist', null],
        'c12' => ['Kavita Reddy', Employee::DESIGNATION_CALLER, 'tl_mumbai', '2025-04-21', '2500000', 'Sourcing Specialist', null],
        'c13' => ['Rohan Mehta', Employee::DESIGNATION_CALLER, 'tl_mumbai', '2025-09-15', '2500000', 'Sourcing Specialist', null],
        'c14' => ['Ajay Agarwal', Employee::DESIGNATION_CALLER, 'tl_pune', '2025-01-27', '3500000', 'Senior Sourcing Specialist', null],
        'c15' => ['Shreya Verma', Employee::DESIGNATION_CALLER, 'tl_pune', '2025-06-16', '2500000', 'Sourcing Specialist', null],
        'c16' => ['Arjun Kumar', Employee::DESIGNATION_CALLER, 'tl_pune', '2025-10-06', '2500000', 'Sourcing Specialist', null],
        // Under a skip-level TL with only two callers — the understaffed top-up case.
        'c17' => ['Vivek Bansal', Employee::DESIGNATION_CALLER, 'tl_west_direct', '2025-03-24', '2500000', 'Sourcing Specialist', null],
        'c18' => ['Meera Sharma', Employee::DESIGNATION_CALLER, 'tl_west_direct', '2025-05-05', '3000000', 'Sourcing Specialist', null],
        // Skip-level caller: reports straight to a Manager.
        'c19' => ['Karan Singh', Employee::DESIGNATION_CALLER, 'mgr_pune', '2025-08-04', '2500000', 'Sourcing Specialist', null],
        // Mid-month joiner: partial-month target.
        'c20' => ['Pooja Nair', Employee::DESIGNATION_CALLER, 'tl_delhi_b', '2026-09-08', '2500000', 'Sourcing Specialist', null],

        'mis' => ['Deepak Iyer', Employee::DESIGNATION_ADMIN, null, '2024-02-05', null, 'MIS Executive', 'demo.mis'],
        'accounts' => ['Ritu Agarwal', Employee::DESIGNATION_ADMIN, null, '2024-02-05', null, 'Accounts Executive', 'demo.accounts'],
        'it' => ['Nikhil Reddy', Employee::DESIGNATION_ADMIN, null, '2024-01-15', null, 'IT Support', 'demo.it'],

        'obs_1' => ['Anjali Verma', Employee::DESIGNATION_OTHER_BANK_SUPPORT, null, '2025-04-07', null, 'Other Bank Support', 'demo.otherbank'],
        'obs_2' => ['Rahul Joshi', Employee::DESIGNATION_OTHER_BANK_SUPPORT, null, '2025-09-01', null, 'Other Bank Support', null],
    ];

    /**
     * Roles for the seats outside the hierarchy.
     *
     * @var array<string, string>
     */
    protected const OUT_OF_TREE_ROLES = [
        'mis' => 'MIS',
        'accounts' => 'Accounts',
        'it' => 'IT',
        'obs_1' => OtherBankSupportService::ROLE,
        'obs_2' => OtherBankSupportService::ROLE,
    ];

    public function run(): void
    {
        $password = $this->resolvePassword();

        $admin = $this->createLogin(
            name: (string) config('demo.user.name'),
            email: (string) config('demo.user.email'),
            password: $password,
            role: 'Admin',
            employee: null,
        );

        DemoSeedState::recordLogin('Admin', $admin->name, $admin->email);

        $reportingLines = app(ReportingLineService::class);
        $employees = [];
        $sequence = 100;

        foreach (self::STAFF as $seat => $definition) {
            [$name, $designation, $bossSeat, $doj, $category, $position, $loginLocalPart] = $definition;
            $exitDate = $definition[7] ?? null;

            $bossId = $bossSeat !== null ? $employees[$bossSeat]->id : null;
            $email = DemoSeedState::email($loginLocalPart ?? Str::slug($name, '.'));
            $empId = 'DM'.str_pad((string) ++$sequence, 4, '0', STR_PAD_LEFT);

            $employee = $this->replay($this->momentOn(Carbon::parse($doj), 10), $admin, fn (): Employee => Employee::query()->create([
                'emp_id' => $empId,
                'emp_name' => $name,
                'email' => $email,
                'designation' => $designation,
                'doj' => $doj,
                'reporting_date' => $doj,
                ...$reportingLines->columnsUnder($bossId),
                'cost_center' => $this->costCenter($seat, $bossSeat, $employees),
                'unit_name' => 'Demo Sales',
                'category' => $category,
                'position' => $position,
                'exit_status' => $exitDate ? 'yes' : 'no',
                'exit_date' => $exitDate,
            ]));

            $employees[$seat] = $employee;

            $role = self::OUT_OF_TREE_ROLES[$seat] ?? HierarchyRoleService::ROLES[$designation];

            $user = $this->createLogin($name, $email, $password, $role, $employee);

            if ($loginLocalPart !== null) {
                DemoSeedState::recordLogin($role, $name, $email);
            }

            if ($exitDate !== null) {
                $user->forceFill(['is_active' => false])->save();
            }
        }
    }

    /**
     * Keeps the original demo-login contract: the password comes from
     * DEMO_USER_PASSWORD, and when that is empty a random one is generated
     * and printed exactly once. Every demo login shares it.
     */
    protected function resolvePassword(): string
    {
        $configured = config('demo.user.password');

        if (filled($configured)) {
            DemoSeedState::setPassword((string) $configured);

            return (string) $configured;
        }

        $generated = Str::password(16);
        DemoSeedState::setPassword($generated);

        $this->command?->warn("Generated demo password (shown once, shared by every demo login): {$generated}");

        return $generated;
    }

    protected function createLogin(string $name, string $email, string $password, string $role, ?Employee $employee): User
    {
        if (User::query()->where('email', $email)->exists()) {
            throw new RuntimeException("Demo login [{$email}] already exists — rebuild with `php artisan demo:migrate --fresh --seed`.");
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);

        // employee_id is deliberately not fillable on User.
        $user->forceFill([
            'employee_id' => $employee?->id,
            'email_verified_at' => now(),
        ])->save();

        $user->assignRole($role);

        return $user;
    }

    /**
     * Cost centre follows the branch, as on the live data.
     *
     * @param  array<string, Employee>  $employees
     */
    protected function costCenter(string $seat, ?string $bossSeat, array $employees): string
    {
        if (str_starts_with($seat, 'mgr_') || str_starts_with($seat, 'cm_')) {
            return Str::after($seat, '_');
        }

        if ($bossSeat !== null && isset($employees[$bossSeat])) {
            return (string) $employees[$bossSeat]->cost_center;
        }

        return 'head_office';
    }
}
