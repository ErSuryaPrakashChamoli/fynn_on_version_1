<?php

namespace Tests\Feature;

use App\Enums\CibilReportStatus;
use App\Filament\Pages\CibilAccessSettings;
use App\Filament\Pages\CibilAuditHistory;
use App\Filament\Pages\CibilDashboard;
use App\Filament\Pages\CibilSettings;
use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Filament\Resources\CibilRequests\CibilRequestResource;
use App\Filament\Resources\CibilRequests\Pages\ListCibilRequests;
use App\Filament\Widgets\CibilStats;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Models\CibilReport;
use App\Models\CibilSetting;
use App\Models\Employee;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CIBIL Management: its sidebar group, the dashboard's real counts, the
 * request monitor (never the report itself), the Admin's settings (never
 * the token), the per-permission access page and the audit history.
 */
class CibilManagementScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caller;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);

        $this->admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $this->admin->assignRole('Admin');

        $employee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $this->caller = User::factory()->create(['employee_id' => $employee->id]);
        $this->caller->assignRole('Caller');
    }

    /**
     * @return list<string>
     */
    private function screenUrls(): array
    {
        return [
            'dashboard' => CibilDashboard::getUrl(),
            'reports' => CibilReportResource::getUrl('index'),
            'requests' => CibilRequestResource::getUrl('index'),
            'failed' => CibilRequestResource::getUrl('index', ['tab' => CibilRequestResource::FAILED_TAB]),
            'settings' => CibilSettings::getUrl(),
            'access' => CibilAccessSettings::getUrl(),
            'audit' => CibilAuditHistory::getUrl(),
        ];
    }

    // ---- Navigation ---------------------------------------------------------

    public function test_the_admin_sees_the_whole_cibil_management_group(): void
    {
        $this->actingAs($this->admin);

        $response = $this->followingRedirects()->get('/admin')->assertOk()->assertSee('CIBIL Management');

        foreach ($this->screenUrls() as $url) {
            $response->assertSee(e($url), false);
        }

        foreach ($this->screenUrls() as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_a_login_with_access_gets_the_working_screens_but_not_settings_access_or_audit(): void
    {
        CibilAccess::sync(['Caller'], []);
        $this->actingAs($this->caller->fresh());

        $urls = $this->screenUrls();
        $response = $this->followingRedirects()->get('/admin')->assertOk();

        foreach (['dashboard', 'reports', 'requests', 'failed'] as $screen) {
            $response->assertSee(e($urls[$screen]), false);
            $this->get($urls[$screen])->assertOk();
        }

        foreach (['settings', 'access', 'audit'] as $screen) {
            $response->assertDontSee(e($urls[$screen]), false);
            $this->get($urls[$screen])->assertForbidden();
        }
    }

    public function test_without_access_no_cibil_screen_is_listed_or_reachable(): void
    {
        $this->actingAs($this->caller);

        $this->followingRedirects()->get('/admin')->assertOk()->assertDontSee('CIBIL Management');

        foreach ($this->screenUrls() as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    // ---- Dashboard ----------------------------------------------------------

    public function test_the_dashboard_counts_the_real_requests(): void
    {
        CibilReport::factory()->score()->count(2)->create();
        CibilReport::factory()->create();
        CibilReport::factory()->pdf('cibil-reports/1/x.pdf')->create();
        CibilReport::factory()->failed()->create();
        CibilReport::factory()->pending()->create(['created_at' => now()->subMinute()]);
        CibilReport::factory()->uncertain()->create();
        $stale = CibilReport::factory()->pending()->create(['created_at' => now()->subHour()]);

        $this->actingAs($this->admin);

        $this->get(CibilDashboard::getUrl())->assertOk();
        $this->assertSame(CibilReportStatus::Uncertain, $stale->fresh()->status, 'Opening the dashboard settles stale requests.');

        $stats = collect((new ReflectionMethod(CibilStats::class, 'getStats'))->invoke(new CibilStats))
            ->mapWithKeys(fn (Stat $stat): array => [(string) $stat->getLabel() => $stat->getValue()]);

        $this->assertSame(8, $stats['Total requests']);
        $this->assertSame(2, $stats['Score checks fetched']);
        $this->assertSame(1, $stats['Full reports fetched']);
        $this->assertSame(1, $stats['PDF requests']);
        $this->assertSame(1, $stats['Failed requests']);
        $this->assertSame(1, $stats['Pending requests']);
        $this->assertSame(2, $stats['Outcome uncertain']);
    }

    // ---- Requests -----------------------------------------------------------

    public function test_the_request_monitor_lists_each_request_but_never_the_report(): void
    {
        $fetched = CibilReport::factory()->create(['response_payload' => ['credit_report' => [['accounts' => [['memberShortName' => 'HIDDEN LENDER']]]]]]);
        $failed = CibilReport::factory()->failed('The provider rejected the request.')->create();
        $uncertain = CibilReport::factory()->uncertain()->create();

        $this->actingAs($this->admin);

        Livewire::test(ListCibilRequests::class)
            ->assertCanSeeTableRecords([$fetched, $failed, $uncertain])
            ->assertSee('The provider rejected the request.')
            ->assertDontSee('HIDDEN LENDER')
            ->set('activeTab', CibilRequestResource::FAILED_TAB)
            ->assertCanSeeTableRecords([$failed, $uncertain])
            ->assertCanNotSeeTableRecords([$fetched]);
    }

    // ---- Settings -----------------------------------------------------------

    public function test_the_admin_switches_requests_on_and_the_token_is_never_shown(): void
    {
        config(['cibil.driver' => 'surepass', 'cibil.surepass.token' => 'tok-super-secret-123']);
        $this->actingAs($this->admin);

        $this->assertFalse(CibilSetting::current()->requests_enabled);

        Livewire::test(CibilSettings::class)
            ->assertSee('Configured')
            ->assertSee('Live Surepass')
            ->assertDontSee('tok-super-secret-123')
            ->fillForm(['requests_enabled' => true, 'pdf_enabled' => false, 'reuse_window_days' => 30])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('CIBIL settings saved');

        $settings = CibilSetting::current();
        $this->assertTrue($settings->requests_enabled);
        $this->assertFalse($settings->pdf_enabled);
        $this->assertSame(30, $settings->reuse_window_days);
        $this->assertSame($this->admin->id, $settings->updated_by);

        $log = Activity::query()->where('description', 'CIBIL settings updated')->sole();
        $this->assertSame(CibilReportService::LOG_NAME, $log->log_name);
        $this->assertStringNotContainsString('tok-super-secret-123', json_encode($log->properties));
    }

    // ---- Access -------------------------------------------------------------

    public function test_the_access_page_grants_the_finer_permissions_per_role_and_user(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CibilAccessSettings::class)
            ->set('data.roles', ['Caller'])
            ->set('data.grants.cibil_pdf.roles', ['Caller'])
            ->set('data.grants.cibil_audit.users', [$this->caller->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('CIBIL access saved');

        $caller = $this->caller->fresh();
        $this->assertTrue(CibilAccess::canDownloadPdf($caller));
        $this->assertTrue(CibilAccess::canViewAudit($caller));
        $this->assertFalse(CibilAccess::canViewFullReport($caller));
        $this->assertFalse(CibilAccess::canRefresh($caller));
        $this->assertSame(['Caller'], CibilAccess::grantedRoleNames(CibilAccess::PDF_PERMISSION));
        $this->assertSame([$this->caller->id], CibilAccess::grantedUserIds(CibilAccess::AUDIT_PERMISSION));

        Livewire::test(CibilAccessSettings::class)
            ->assertSet('data.grants.cibil_pdf.roles', ['Caller']);
    }

    // ---- Audit history -----------------------------------------------------

    public function test_the_audit_history_is_for_the_admin_and_the_audit_permission_within_their_reach(): void
    {
        CibilAccess::sync(['Caller'], []);
        $caller = $this->caller->fresh();
        $service = app(CibilReportService::class);

        $theirs = CibilReport::factory()->create(['requested_by' => $caller->id, 'customer_name' => 'Visible Applicant']);
        $hidden = CibilReport::factory()->create(['requested_by' => $this->admin->id, 'customer_name' => 'Hidden Applicant']);
        $service->recordView($theirs, $caller);
        $service->recordView($hidden, $this->admin);

        $this->actingAs($this->admin);
        Livewire::test(CibilAuditHistory::class)
            ->assertSee('CIBIL report viewed for Visible Applicant')
            ->assertSee('CIBIL report viewed for Hidden Applicant');

        $this->actingAs($caller);
        $this->assertFalse(CibilAuditHistory::canAccess());

        CibilAccess::sync(['Caller'], [], CibilAccess::AUDIT_PERMISSION);
        $this->actingAs($this->caller->fresh());

        Livewire::test(CibilAuditHistory::class)
            ->assertSee('CIBIL report viewed for Visible Applicant')
            ->assertDontSee('CIBIL report viewed for Hidden Applicant');
    }
}
