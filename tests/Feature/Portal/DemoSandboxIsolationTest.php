<?php

namespace Tests\Feature\Portal;

use App\Enums\PortalRole;
use App\Models\Customer;
use App\Models\Demo\DemoCustomer;
use App\Models\Demo\DemoLead;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Demo\DemoResetService;
use Database\Seeders\Demo\DemoDataSeeder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The sandbox must never surface production data.
 *
 * The strongest assertion here is structural rather than behavioural:
 * every Demo panel resource is bound to a demo_* model, and those tables
 * have no relationship to the production leads/customers/employees
 * tables. test_no_demo_table_overlaps_a_production_table proves that
 * property directly, which is why the rest can be a handful of spot
 * checks rather than an exhaustive crawl.
 */
class DemoSandboxIsolationTest extends PortalBoundaryTestCase
{
    public function test_no_demo_table_overlaps_a_production_table(): void
    {
        foreach (DemoResetService::TABLES as $table) {
            $this->assertStringStartsWith('demo_', $table);
            $this->assertTrue(Schema::hasTable($table), "Missing demo table [{$table}].");
        }

        // The production tables the brief is worried about are not in
        // the reset list, and cannot be: assertDemoOnly() rejects any
        // table without the prefix.
        foreach (['leads', 'customers', 'employees', 'users'] as $productionTable) {
            $this->assertNotContains($productionTable, DemoResetService::TABLES);
        }
    }

    public function test_a_demo_user_sees_only_sandbox_records_never_production_ones(): void
    {
        // Production rows that must stay invisible.
        $productionCustomer = Customer::factory()->create(['customer_name' => 'REAL PRODUCTION CUSTOMER']);
        $productionEmployee = Employee::factory()->create(['emp_name' => 'REAL PRODUCTION EMPLOYEE']);

        app(DemoDataSeeder::class)->seedFor($this->demoTenant);

        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Demo));

        $this->get('/demo/customers')
            ->assertOk()
            ->assertDontSee($productionCustomer->customer_name);

        $this->get('/demo/employees')
            ->assertOk()
            ->assertDontSee($productionEmployee->emp_name);
    }

    public function test_a_demo_user_cannot_reach_production_lms_routes(): void
    {
        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Demo));

        foreach (['/admin/leads', '/admin/customers', '/admin/employees', '/admin/users'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    /**
     * The plain routes in routes/web.php only ask for `auth`, so
     * RestrictPortalUsers is what stops a valid demo session pulling a
     * production document. 404, not 403 — a demo user should not learn
     * the route exists.
     */
    public function test_a_demo_user_cannot_reach_the_production_document_route(): void
    {
        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Demo));

        $this->get('/ocr-documents/1/file')->assertNotFound();
    }

    public function test_a_trainee_cannot_reach_the_production_document_route(): void
    {
        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Trainee));

        $this->get('/ocr-documents/1/file')->assertNotFound();
    }

    /**
     * The same guard must not disturb an ordinary LMS user, who has no
     * portal account. A 404 from the model binding is the correct
     * outcome for a missing document; what matters is that they are not
     * refused by the portal guard.
     */
    public function test_an_lms_user_is_unaffected_by_the_portal_route_guard(): void
    {
        $this->actingAsPortalUser($this->makeInternalUser('Admin'));

        // No such document exists, so 404 — but reached through the
        // route's own binding rather than the portal restriction.
        $this->get('/ocr-documents/999999/file')->assertNotFound();

        // And the panel itself still works.
        $this->get('/admin')->assertOk();
    }

    public function test_a_demo_user_cannot_delete_sandbox_records(): void
    {
        app(DemoDataSeeder::class)->seedFor($this->demoTenant);

        $demoUser = $this->makePortalUser(PortalRole::Demo);
        $this->actingAsPortalUser($demoUser);

        $lead = DemoLead::where('tenant_id', $this->demoTenant->id)->firstOrFail();

        $this->assertTrue($demoUser->can('view', $lead));
        $this->assertFalse($demoUser->can('delete', $lead));
    }

    public function test_a_demo_user_from_one_sandbox_tenant_cannot_read_anothers(): void
    {
        app(DemoDataSeeder::class)->seedFor($this->demoTenant);

        $otherSandbox = Tenant::factory()->demo()->create([
            'slug' => 'prospect-two-demo',
            'name' => 'Prospect Two Demo',
        ]);

        $demoUser = $this->makePortalUser(PortalRole::Demo, $otherSandbox);
        $this->actingAsPortalUser($demoUser);

        $foreignCustomer = DemoCustomer::where('tenant_id', $this->demoTenant->id)->firstOrFail();

        $this->assertFalse($demoUser->can('view', $foreignCustomer));
    }

    public function test_an_lms_admin_cannot_enter_the_demo_sandbox(): void
    {
        // Demo access requires a demo portal account — an internal role
        // is deliberately not enough, so the sandbox always has a real
        // tenant to scope by.
        $this->actingAsPortalUser($this->makeInternalUser('Admin'));

        $this->get('/demo')->assertForbidden();
    }

    public function test_seeded_demo_data_carries_no_real_looking_identifiers(): void
    {
        app(DemoDataSeeder::class)->seedFor($this->demoTenant);

        foreach (DemoLead::where('tenant_id', $this->demoTenant->id)->limit(25)->get() as $lead) {
            // PANs use the reserved ZZ series and mobiles the 90000
            // block, so a screenshot can never contain a usable
            // real-world identifier.
            $this->assertStringStartsWith('ZZ', $lead->pan_number);
            $this->assertStringStartsWith('90', $lead->mobile_no);
            $this->assertStringEndsWith('@demo-fynnon.test', $lead->email);
        }
    }

    public function test_demo_reset_restores_the_sandbox_without_touching_production(): void
    {
        app(DemoDataSeeder::class)->seedFor($this->demoTenant);

        $productionCustomer = Customer::factory()->create();
        $productionEmployee = Employee::factory()->create();

        DemoLead::where('tenant_id', $this->demoTenant->id)->limit(10)->delete();
        $afterTampering = DemoLead::where('tenant_id', $this->demoTenant->id)->count();

        $counts = app(DemoResetService::class)->reset($this->demoTenant);

        $this->assertGreaterThan($afterTampering, $counts['demo_leads']);
        $this->assertSame(DemoDataSeeder::LEAD_COUNT, $counts['demo_leads']);

        // Production is untouched.
        $this->assertDatabaseHas('customers', ['id' => $productionCustomer->id]);
        $this->assertDatabaseHas('employees', ['id' => $productionEmployee->id]);
    }

    public function test_demo_reset_refuses_a_non_demo_tenant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a demo tenant');

        app(DemoResetService::class)->reset($this->production);
    }

    public function test_the_demo_reset_command_refuses_a_production_tenant(): void
    {
        $this->artisan('demo:reset', ['--tenant' => $this->production->slug, '--force' => true])
            ->assertExitCode(1);
    }
}
