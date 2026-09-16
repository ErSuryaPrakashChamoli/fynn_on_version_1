<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\OtherBankSupportDashboard;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\OtherBankIncentiveSlabs\OtherBankIncentiveSlabResource;
use App\Filament\Resources\OtherBankIncentiveSlabs\Pages\CreateOtherBankIncentiveSlab;
use App\Filament\Resources\OtherBankSupportTargets\OtherBankSupportTargetResource;
use App\Filament\Resources\OtherBankSupportTargets\Pages\CreateOtherBankSupportTarget;
use App\Filament\Widgets\OtherBankSupportStats;
use App\Models\Employee;
use App\Models\OtherBankIncentiveSlab;
use App\Models\OtherBankSupportTarget;
use App\Models\User;
use App\Services\DailyCommitmentGate;
use App\Services\MonthlyTargetGate;
use App\Services\OtherBankSupportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Admin's side of the module (monthly targets, slab ladder) and the
 * business page / dashboard the support team reads it on.
 */
class OtherBankSupportAdminModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Manager', OtherBankSupportService::ROLE] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->support = User::factory()->create();
        $this->support->assignRole(OtherBankSupportService::ROLE);
    }

    public function test_only_admin_manages_targets_and_slabs_while_support_and_admin_see_the_business_page(): void
    {
        $manager = User::factory()->create([
            'employee_id' => Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER])->id,
        ]);
        $manager->assignRole('Manager');

        $this->actingAs($this->admin);
        $this->assertTrue(OtherBankSupportTargetResource::canAccess());
        $this->assertTrue(OtherBankIncentiveSlabResource::canAccess());
        $this->assertTrue(OtherBankSupportDashboard::canAccess());

        $this->actingAs($this->support);
        $this->assertFalse(OtherBankSupportTargetResource::canAccess());
        $this->assertFalse(OtherBankIncentiveSlabResource::canAccess());
        $this->assertTrue(OtherBankSupportDashboard::canAccess());

        $this->actingAs($manager);
        $this->assertFalse(OtherBankSupportTargetResource::canAccess());
        $this->assertFalse(OtherBankIncentiveSlabResource::canAccess());
        $this->assertFalse(OtherBankSupportDashboard::canAccess());
    }

    public function test_admin_sets_a_monthly_target_and_a_second_one_for_the_same_month_is_refused(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateOtherBankSupportTarget::class)
            ->fillForm(['user_id' => $this->support->id, 'month' => '2026-10-17', 'target_amount' => 2500000])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertEquals(
            2500000,
            OtherBankSupportTarget::query()->where('user_id', $this->support->id)->forMonth(Carbon::parse('2026-10-01'))->value('target_amount'),
        );

        Livewire::test(CreateOtherBankSupportTarget::class)
            ->fillForm(['user_id' => $this->support->id, 'month' => '2026-10-01', 'target_amount' => 100])
            ->call('create')
            ->assertHasFormErrors(['user_id']);

        $this->assertSame(1, OtherBankSupportTarget::query()->count());
    }

    public function test_admin_defines_fixed_and_percentage_slabs_and_duplicate_minimums_are_refused(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateOtherBankIncentiveSlab::class)
            ->fillForm([
                'effective_month' => '2026-10-01',
                'min_achievement' => 1000000,
                'payout_type' => OtherBankIncentiveSlab::PAYOUT_PERCENTAGE,
                'payout_value' => 1.25,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $slab = OtherBankIncentiveSlab::query()->sole();
        $this->assertSame('1.25% of achievement', $slab->payoutLabel());

        Livewire::test(CreateOtherBankIncentiveSlab::class)
            ->fillForm([
                'effective_month' => '2026-10-01',
                'min_achievement' => 1000000,
                'payout_type' => OtherBankIncentiveSlab::PAYOUT_FIXED,
                'payout_value' => 5000,
            ])
            ->call('create')
            ->assertHasFormErrors(['min_achievement']);

        Livewire::test(CreateOtherBankIncentiveSlab::class)
            ->fillForm([
                'effective_month' => '2026-10-01',
                'min_achievement' => 2000000,
                'payout_type' => OtherBankIncentiveSlab::PAYOUT_PERCENTAGE,
                'payout_value' => 150,
            ])
            ->call('create')
            ->assertHasFormErrors(['payout_value']);
    }

    public function test_business_page_renders_for_the_support_user_and_the_admin(): void
    {
        $this->actingAs($this->support);

        Livewire::test(OtherBankSupportDashboard::class)
            ->assertOk()
            ->assertSee('Other bank business')
            ->assertSee('My target')
            ->assertDontSee('Support team');

        $this->actingAs($this->admin);

        Livewire::test(OtherBankSupportDashboard::class)
            ->assertOk()
            ->assertSee('Support team')
            ->assertSee($this->support->name);
    }

    public function test_support_employee_is_created_outside_the_tree_without_a_target_category(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'emp_id' => 'FA000009',
                'emp_name' => 'Raja Kush',
                'email' => 'raja.kush@fynnedge.com',
                'position' => 'Sales Support Coordinator',
                'designation' => Employee::DESIGNATION_OTHER_BANK_SUPPORT,
                'cost_center' => 'rohit_sharma',
                'unit_name' => 'rohit_sharma',
                'exit_status' => 'no',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $employee = Employee::query()->where('emp_id', 'FA000009')->sole();

        // Outside the reporting tree: no rank, no boss, no LMS target.
        $this->assertSame(0, Employee::designationRank($employee->designation));
        $this->assertNull($employee->directBossId());
        $this->assertNull($employee->category);
        $this->assertSame('Other Bank Support', Employee::designationOptions()[$employee->designation]);
    }

    public function test_support_employee_is_never_chased_for_targets_or_daily_commitments(): void
    {
        $employee = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_OTHER_BANK_SUPPORT,
            'exit_status' => 'no',
        ]);

        $this->support->update(['employee_id' => $employee->id]);
        $this->actingAs($this->support);

        $this->assertNotContains(Employee::DESIGNATION_OTHER_BANK_SUPPORT, MonthlyTargetGate::REQUIRES_TARGET);
        $this->assertFalse(app(MonthlyTargetGate::class)->isBlocked($this->support));
        $this->assertFalse(app(MonthlyTargetGate::class)->isTargetSetter($this->support));
        $this->assertFalse(app(DailyCommitmentGate::class)->requiresCommitment($this->support));

        // Still a support user, with the module's own target rather than an LMS one.
        $this->assertTrue(OtherBankSupportService::isScopedSupportUser($this->support));
    }

    public function test_support_user_dashboard_carries_only_the_support_widget(): void
    {
        $this->actingAs($this->support);

        $this->assertSame([OtherBankSupportStats::class], (new Dashboard)->getWidgets());
        $this->assertTrue(OtherBankSupportStats::canView());

        Livewire::test(OtherBankSupportStats::class)
            ->assertOk()
            ->assertSee('Other Bank Business')
            ->assertSee('My Target');

        $this->actingAs($this->admin);

        $this->assertNotContains(OtherBankSupportStats::class, (new Dashboard)->getWidgets());
        $this->assertFalse(OtherBankSupportStats::canView());
    }
}
