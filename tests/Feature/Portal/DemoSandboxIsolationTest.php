<?php

namespace Tests\Feature\Portal;

use App\Enums\PortalRole;
use App\Models\Customer;
use App\Models\Demo\DemoCustomer;
use App\Models\Demo\DemoLead;
use App\Models\Employee;
use App\Services\Demo\DemoResetService;
use Database\Seeders\Demo\DemoDataSeeder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The sandbox must never surface production data.
 *
 * The strongest assertion here is structural: every Demo table lives on
 * the separate demo database and none of them exists in the main one.
 * test_demo_tables_exist_only_in_the_demo_database proves that directly,
 * which is why the rest can be a handful of spot checks rather than an
 * exhaustive crawl.
 */
class DemoSandboxIsolationTest extends PortalBoundaryTestCase
{
    public function test_demo_tables_exist_only_in_the_demo_database(): void
    {
        foreach ([...DemoResetService::TABLES, 'demo_users'] as $table) {
            $this->assertStringStartsWith('demo_', $table);
            $this->assertTrue(Schema::connection('demo')->hasTable($table), "Missing demo table [{$table}].");
            $this->assertFalse(Schema::hasTable($table), "Demo table [{$table}] must not exist in the main database.");
        }

        // And the main tables are not reachable from the demo connection.
        foreach (['leads', 'customers', 'employees', 'users'] as $productionTable) {
            $this->assertNotContains($productionTable, DemoResetService::TABLES);
            $this->assertFalse(Schema::connection('demo')->hasTable($productionTable));
        }
    }

    public function test_a_demo_user_sees_only_sandbox_records_never_production_ones(): void
    {
        // Production rows that must stay invisible.
        $productionCustomer = Customer::factory()->create(['customer_name' => 'REAL PRODUCTION CUSTOMER']);
        $productionEmployee = Employee::factory()->create(['emp_name' => 'REAL PRODUCTION EMPLOYEE']);

        app(DemoDataSeeder::class)->run();

        $this->actingAsDemoUser($this->makeDemoUser());

        $this->get('/demo/customers')
            ->assertOk()
            ->assertDontSee($productionCustomer->customer_name);

        $this->get('/demo/employees')
            ->assertOk()
            ->assertDontSee($productionEmployee->emp_name);
    }

    public function test_a_demo_user_cannot_reach_production_lms_routes(): void
    {
        $this->actingAsDemoUser($this->makeDemoUser());

        foreach (['/admin/leads', '/admin/customers', '/admin/employees', '/admin/users'] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
        }
    }

    /**
     * The plain routes in routes/web.php authenticate on the `web` guard,
     * where a DemoUser does not exist.
     */
    public function test_a_demo_user_cannot_reach_the_production_document_route(): void
    {
        $this->actingAsDemoUser($this->makeDemoUser());

        $response = $this->get('/ocr-documents/1/file');

        $this->assertFalse($response->isSuccessful());
        $this->assertNotSame(404, $response->getStatusCode(), 'The request should be refused as a guest, not reach the route binding.');
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

        $this->get('/ocr-documents/999999/file')->assertNotFound();

        $this->get('/admin')->assertOk();
    }

    public function test_a_demo_user_cannot_delete_sandbox_records(): void
    {
        app(DemoDataSeeder::class)->run();

        $demoUser = $this->makeDemoUser();
        $this->actingAsDemoUser($demoUser);

        $lead = DemoLead::query()->firstOrFail();

        $this->assertTrue($demoUser->can('view', $lead));
        $this->assertTrue($demoUser->can('update', $lead));
        $this->assertFalse($demoUser->can('delete', $lead));
    }

    /**
     * DemoRecordPolicy is typed on DemoUser, so the Gate never grants a
     * main LMS user — even an Admin — any ability on a sandbox record.
     */
    public function test_a_main_admin_is_refused_every_sandbox_ability(): void
    {
        $admin = $this->makeInternalUser('Admin');
        $customer = DemoCustomer::factory()->create();

        foreach (['viewAny', 'create', 'deleteAny'] as $ability) {
            $this->assertFalse($admin->can($ability, DemoCustomer::class), "Admin was granted [{$ability}].");
        }

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertFalse($admin->can($ability, $customer), "Admin was granted [{$ability}].");
        }
    }

    public function test_an_lms_admin_is_not_signed_in_to_the_demo_sandbox(): void
    {
        // Demo access requires a DemoUser on the `demo` guard — a main
        // session, whatever its role, is a guest on /demo.
        $this->actingAsPortalUser($this->makeInternalUser('Admin'));

        $this->get('/demo')->assertRedirect('/demo/login');
    }

    public function test_seeded_demo_data_carries_no_real_looking_identifiers(): void
    {
        app(DemoDataSeeder::class)->run();

        foreach (DemoLead::query()->limit(25)->get() as $lead) {
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
        app(DemoDataSeeder::class)->run();
        $demoUser = $this->makeDemoUser();

        $productionCustomer = Customer::factory()->create();
        $productionEmployee = Employee::factory()->create();

        DemoLead::query()->limit(10)->delete();
        $afterTampering = DemoLead::query()->count();

        $counts = app(DemoResetService::class)->reset();

        $this->assertGreaterThan($afterTampering, $counts['demo_leads']);
        $this->assertSame(DemoDataSeeder::LEAD_COUNT, $counts['demo_leads']);

        // Production is untouched, and so is the demo login.
        $this->assertDatabaseHas('customers', ['id' => $productionCustomer->id]);
        $this->assertDatabaseHas('employees', ['id' => $productionEmployee->id]);
        $this->assertDatabaseHas('demo_users', ['id' => $demoUser->id], 'demo');
    }

    public function test_demo_reset_refuses_when_the_demo_connection_points_at_the_main_database(): void
    {
        config(['database.connections.demo' => config('database.connections.'.config('database.default'))]);
        config(['database.connections.demo.database' => 'shared_database']);
        config(['database.connections.'.config('database.default').'.database' => 'shared_database']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('resolves to the main database');

        app(DemoResetService::class)->reset();
    }

    public function test_the_demo_reset_command_refuses_a_misconfigured_demo_database(): void
    {
        config(['database.connections.demo.database' => null]);

        $this->artisan('demo:reset', ['--force' => true])
            ->assertExitCode(1);
    }
}
