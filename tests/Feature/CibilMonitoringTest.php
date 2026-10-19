<?php

namespace Tests\Feature;

use App\Enums\CibilFailureCategory;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Filament\Pages\CibilDashboard;
use App\Filament\Pages\CibilEmployeeActivity;
use App\Filament\Pages\CibilSettings;
use App\Filament\Widgets\CibilStats;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Models\CibilReport;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Support\CibilAccess;
use App\Support\CibilDashboardFilters;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The monitoring side of the bureau module: the dashboard's filters and
 * figures (all counted from request rows, nothing estimated), the Employee
 * Activity page and who may see whose activity, and the health lines on
 * the settings page.
 */
class CibilMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $caller;

    private User $outsider;

    private Customer $callerFile;

    private Customer $outsiderFile;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Manager', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));

        $this->admin = User::factory()->create(['name' => 'Asha Admin', 'employee_id' => Employee::factory()->create()->id]);
        $this->admin->assignRole('Admin');

        $managerEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER, 'exit_status' => 'no']);
        $this->manager = User::factory()->create(['name' => 'Meera Manager', 'employee_id' => $managerEmployee->id]);
        $this->manager->assignRole('Manager');

        $callerEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no', 'manager_id' => $managerEmployee->id]);
        $this->caller = User::factory()->create(['name' => 'Ravi Caller', 'employee_id' => $callerEmployee->id]);
        $this->caller->assignRole('Caller');

        $outsiderEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $this->outsider = User::factory()->create(['name' => 'Omar Outsider', 'employee_id' => $outsiderEmployee->id]);
        $this->outsider->assignRole('Caller');

        $this->callerFile = Customer::factory()->create(['assign_to' => $callerEmployee->id, 'employee_id' => $callerEmployee->id]);
        $this->outsiderFile = Customer::factory()->create(['assign_to' => $outsiderEmployee->id, 'employee_id' => $outsiderEmployee->id]);

        CibilAccess::sync(['Manager', 'Caller'], []);
        CibilAccess::sync(['Manager'], [], CibilAccess::AUDIT_PERMISSION);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function request(User $by, Customer $file, array $attributes = []): CibilReport
    {
        return CibilReport::factory()->create([
            'requested_by' => $by->id,
            'customer_id' => $file->id,
            'duration_ms' => null,
            ...$attributes,
        ]);
    }

    // ---- Dashboard ---------------------------------------------------------

    public function test_the_filters_narrow_by_period_bureau_request_type_and_file(): void
    {
        $today = $this->request($this->caller, $this->callerFile, ['bureau' => CreditBureau::Experian, 'request_type' => CibilRequestType::Score]);
        $lastWeek = $this->request($this->caller, $this->callerFile, ['created_at' => now()->subDays(5)]);
        $lastMonth = $this->request($this->caller, $this->callerFile, ['created_at' => now()->subMonth()]);
        $unlinked = CibilReport::factory()->create(['created_at' => now()->subDays(60), 'lead_id' => null, 'customer_assignment_id' => null, 'customer_id' => null]);

        $ids = fn (array $filters): array => CibilDashboardFilters::apply(CibilReport::query(), $filters)->orderBy('id')->pluck('id')->all();

        $this->assertSame([$today->id], $ids(['period' => 'today']));
        $this->assertSame([$today->id, $lastWeek->id], $ids(['period' => 'last_7_days']));
        $this->assertSame([$today->id, $lastWeek->id], $ids(['period' => 'this_month']));
        $this->assertSame([$lastMonth->id], $ids(['period' => 'previous_month']));
        $this->assertSame([$lastWeek->id, $lastMonth->id], $ids(['period' => 'custom', 'from' => now()->subMonth()->toDateString(), 'until' => now()->subDays(5)->toDateString()]));
        $this->assertSame([$today->id], $ids(['bureau' => 'experian']));
        $this->assertSame([$today->id], $ids(['request_type' => 'score']));
        $this->assertSame([$today->id, $lastWeek->id, $lastMonth->id], $ids(['fetched_for' => 'customer']));
        $this->assertSame([], $ids(['fetched_for' => 'lead']));
        $this->assertCount(4, $ids(['period' => 'all']));
        $this->assertNotContains($unlinked->id, $ids(['fetched_for' => 'customer']));
    }

    public function test_the_dashboard_figures_come_from_the_filtered_requests(): void
    {
        $this->request($this->caller, $this->callerFile, ['bureau' => CreditBureau::Experian, 'duration_ms' => 1000]);
        $this->request($this->caller, $this->callerFile, ['bureau' => CreditBureau::Experian, 'duration_ms' => 3000]);
        CibilReport::factory()->experian()->failed()->create(['requested_by' => $this->caller->id, 'customer_id' => $this->callerFile->id, 'failure_category' => CibilFailureCategory::ProviderRejected]);
        CibilReport::factory()->experian()->uncertain()->create(['requested_by' => $this->caller->id, 'lead_id' => null, 'failure_category' => CibilFailureCategory::Timeout]);
        // Outside the filters: CIBIL, and last month's Experian.
        $this->request($this->caller, $this->callerFile, ['duration_ms' => 9000]);
        $this->request($this->caller, $this->callerFile, ['bureau' => CreditBureau::Experian, 'created_at' => now()->subMonth(), 'duration_ms' => 9000]);

        $this->actingAs($this->admin);

        Livewire::test(CibilStats::class, ['pageFilters' => ['period' => 'this_month', 'bureau' => 'experian']])
            ->assertSeeInOrder(['Total requests', '4'])
            ->assertSee('Success rate 50%')
            ->assertSee('Failure rate 25%')
            ->assertSee('1 timed out')
            ->assertSee('2 s')
            ->assertSee('1 customer(s) · 0 lead(s)')
            ->assertSee('Not configured');

        Livewire::test(CibilDashboard::class)
            ->assertOk()
            ->assertSee('Period')
            ->assertSee('Employee activity');
    }

    // ---- Employee activity ---------------------------------------------------

    public function test_the_admin_sees_every_employees_activity(): void
    {
        $this->request($this->caller, $this->callerFile, ['request_type' => CibilRequestType::Score, 'duration_ms' => 2000]);
        $this->request($this->caller, $this->callerFile, ['request_type' => CibilRequestType::Score, 'duration_ms' => 4000]);
        $this->request($this->caller, $this->callerFile, ['request_type' => CibilRequestType::Pdf]);
        CibilReport::factory()->failed()->create(['requested_by' => $this->caller->id, 'customer_id' => $this->outsiderFile->id]);
        $this->request($this->outsider, $this->outsiderFile, ['bureau' => CreditBureau::Experian, 'created_at' => now()->subMonth()]);

        $this->actingAs($this->admin);

        Livewire::test(CibilEmployeeActivity::class)
            ->assertCanSeeTableRecords([$this->caller, $this->outsider])
            ->assertCanNotSeeTableRecords([$this->admin, $this->manager])
            ->assertTableColumnStateSet('total_count', 4, $this->caller)
            ->assertTableColumnStateSet('score_count', 2, $this->caller)
            ->assertTableColumnStateSet('pdf_count', 1, $this->caller)
            ->assertTableColumnStateSet('success_count', 3, $this->caller)
            ->assertTableColumnStateSet('failed_count', 1, $this->caller)
            ->assertTableColumnStateSet('customers_count', 2, $this->caller)
            ->assertTableColumnStateSet('success_rate', '75%', $this->caller)
            ->assertTableColumnFormattedStateSet('avg_duration_ms', '3 s', $this->caller)
            // This month only: the outsider's request was last month.
            ->set('filters.period', 'this_month')
            ->assertCanSeeTableRecords([$this->caller])
            ->assertCanNotSeeTableRecords([$this->outsider]);
    }

    public function test_a_manager_with_the_audit_permission_sees_only_their_own_branch(): void
    {
        $this->request($this->caller, $this->callerFile);
        $this->request($this->outsider, $this->outsiderFile);

        $this->actingAs($this->manager->fresh());

        $this->assertTrue(CibilEmployeeActivity::canAccess());

        Livewire::test(CibilEmployeeActivity::class)
            ->assertCanSeeTableRecords([$this->caller])
            ->assertCanNotSeeTableRecords([$this->outsider]);
    }

    public function test_employee_activity_needs_the_audit_permission(): void
    {
        $this->actingAs($this->caller->fresh());

        $this->assertFalse(CibilEmployeeActivity::canAccess());
        $this->get(CibilEmployeeActivity::getUrl())->assertForbidden();
    }

    // ---- Settings health -----------------------------------------------------

    public function test_settings_show_each_bureaus_last_answered_and_failed_request(): void
    {
        $this->request($this->caller, $this->callerFile, ['bureau' => CreditBureau::Experian, 'completed_at' => Carbon::parse('2026-10-18 09:30:00')]);
        CibilReport::factory()->experian()->uncertain()->create(['failure_category' => CibilFailureCategory::Timeout, 'completed_at' => Carbon::parse('2026-10-19 11:00:00')]);

        $this->actingAs($this->admin);

        Livewire::test(CibilSettings::class)
            ->assertSeeInOrder(['Experian', 'Last answered request', '18 Oct 2026, 09:30 AM', 'Last failed request', '19 Oct 2026, 11:00 AM', 'Timeout'])
            ->assertSee('aadhaar-kyc-docs.s3.amazonaws.com')
            ->assertSee('Any https host (no allowlist)');
    }
}
