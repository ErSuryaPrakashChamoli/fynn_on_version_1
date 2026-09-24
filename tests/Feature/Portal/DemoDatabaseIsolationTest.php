<?php

namespace Tests\Feature\Portal;

use App\Filament\Demo\Pages\Auth\DemoLogin;
use App\Filament\Demo\Resources\DemoCustomers\Pages\CreateDemoCustomer;
use App\Filament\Demo\Resources\DemoCustomers\Pages\EditDemoCustomer;
use App\Filament\Demo\Resources\DemoCustomers\Pages\ListDemoCustomers;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\Demo\DemoCustomer;
use App\Models\Demo\DemoUser;
use App\Models\User;
use App\Models\UserLoginSession;
use App\Support\Demo\DemoDatabase;
use Database\Seeders\Demo\DemoDatabaseSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;

/**
 * /admin and /demo run on two separate databases.
 *
 * The main database is the default connection; the demo database is the
 * `demo` connection (its own SQLite :memory: database under phpunit.xml,
 * its own MySQL database in production). These tests prove records land
 * only where they belong, neither panel can list the other's rows,
 * neither panel's users are signed in to the other, and a broken demo
 * configuration fails loudly instead of falling back to main.
 */
class DemoDatabaseIsolationTest extends PortalBoundaryTestCase
{
    public function test_every_demo_model_is_bound_to_the_demo_connection(): void
    {
        $this->assertSame('demo', DemoDatabase::connectionName());

        foreach ([DemoUser::class, DemoCustomer::class] as $model) {
            $this->assertSame('demo', (new $model)->getConnectionName(), "{$model} is not on the demo connection.");
        }

        $this->assertNotSame('demo', (new Customer)->getConnectionName());
        $this->assertNotSame('demo', (new User)->getConnectionName());
    }

    /**
     * Test 1 — a main record is saved in the main database only.
     */
    public function test_a_main_customer_is_stored_only_in_the_main_database(): void
    {
        $customer = Customer::factory()->create(['customer_name' => 'MAIN TEST CUSTOMER']);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'customer_name' => 'MAIN TEST CUSTOMER']);
        $this->assertFalse(Schema::connection('demo')->hasTable('customers'));
    }

    /**
     * Test 2 — a record created through the /demo panel is saved in the
     * demo database only.
     */
    public function test_a_customer_created_in_the_demo_panel_is_stored_only_in_the_demo_database(): void
    {
        $this->actingAsDemoUser($this->makeDemoUser());
        Filament::setCurrentPanel('demo');

        Livewire::test(CreateDemoCustomer::class)
            ->fillForm([
                'customer_name' => 'DEMO TEST CUSTOMER',
                'mobile_no' => '9000000001',
                'salary' => 50000,
                'eligible_loan_amount' => 500000,
                'journey_status' => 'otp',
                'eligibility_status' => 'pending',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('demo_customers', ['customer_name' => 'DEMO TEST CUSTOMER'], 'demo');
        $this->assertFalse(Schema::hasTable('demo_customers'), 'demo_customers must not exist in the main database.');
        $this->assertDatabaseMissing('customers', ['customer_name' => 'DEMO TEST CUSTOMER']);
    }

    public function test_a_demo_customer_can_be_edited_in_the_demo_panel(): void
    {
        $customer = DemoCustomer::factory()->create(['customer_name' => 'BEFORE']);

        $this->actingAsDemoUser($this->makeDemoUser());
        Filament::setCurrentPanel('demo');

        Livewire::test(EditDemoCustomer::class, ['record' => $customer->getKey()])
            ->fillForm(['customer_name' => 'AFTER'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('AFTER', $customer->refresh()->customer_name);
    }

    /**
     * Tests 3 and 4 — both customers exist, each only in its own
     * database, and each panel lists only its own.
     */
    public function test_each_panel_lists_only_its_own_databases_customers(): void
    {
        $mainCustomer = Customer::factory()->create(['customer_name' => 'MAIN TEST CUSTOMER']);
        $demoCustomer = DemoCustomer::factory()->create(['customer_name' => 'DEMO TEST CUSTOMER']);

        $this->assertSame(0, DB::connection('demo')->table('demo_customers')->where('customer_name', 'MAIN TEST CUSTOMER')->count());
        $this->assertSame(0, DB::table('customers')->where('customer_name', 'DEMO TEST CUSTOMER')->count());

        // /demo shows the demo customer and never the main one.
        $this->actingAsDemoUser($this->makeDemoUser());
        Filament::setCurrentPanel('demo');
        Livewire::test(ListDemoCustomers::class)
            ->assertCanSeeTableRecords([$demoCustomer])
            ->assertSee('DEMO TEST CUSTOMER')
            ->assertDontSee('MAIN TEST CUSTOMER');

        // /admin shows the main customer and never the demo one.
        $this->actingAsPortalUser($this->makeInternalUser('Admin'));
        Filament::setCurrentPanel('admin');

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$mainCustomer])
            ->assertSee('MAIN TEST CUSTOMER')
            ->assertDontSee('DEMO TEST CUSTOMER');
    }

    /**
     * Test 5 — a main admin is signed in on /admin only, a DemoUser on
     * /demo only, and neither set of credentials works on the other
     * panel's login form.
     *
     * (Split in two because Filament's navigation is a scoped service:
     * a real request only ever renders one panel, so rendering both
     * panels' pages in one test application would not reflect runtime.)
     */
    public function test_a_main_admin_is_signed_in_to_admin_but_is_a_guest_on_demo(): void
    {
        $this->actingAsPortalUser($this->makeInternalUser('Admin'));

        $this->get('/demo')->assertRedirect('/demo/login');
        $this->get('/admin')->assertOk();
    }

    public function test_a_demo_user_is_signed_in_to_demo_but_is_a_guest_on_admin(): void
    {
        $this->actingAsDemoUser($this->makeDemoUser());

        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/demo')->assertOk();
    }

    public function test_main_credentials_do_not_log_in_to_demo_and_demo_credentials_do_not_log_in_to_admin(): void
    {
        $mainUser = User::factory()->create(['email' => 'main@example.test', 'password' => 'Password@123']);
        $demoUser = $this->makeDemoUser(['email' => 'demo@example.test', 'password' => 'Password@123']);

        Filament::setCurrentPanel('demo');
        Livewire::test(DemoLogin::class)
            ->fillForm(['email' => $mainUser->email, 'password' => 'Password@123'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest('demo');

        Filament::setCurrentPanel('admin');
        Livewire::test(Login::class)
            ->fillForm(['email' => $demoUser->email, 'password' => 'Password@123'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest('web');
    }

    public function test_a_demo_user_can_log_in_to_demo_without_touching_the_main_database(): void
    {
        $demoUser = $this->makeDemoUser(['email' => 'demo@example.test', 'password' => 'Password@123']);
        $loginSessionsBefore = UserLoginSession::query()->count();

        Filament::setCurrentPanel('demo');
        Livewire::test(DemoLogin::class)
            ->fillForm(['email' => $demoUser->email, 'password' => 'Password@123'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($demoUser, 'demo');
        $this->assertGuest('web');

        // The Login event fires for the demo guard too, but must not open
        // a user_login_sessions row in the main database.
        $this->assertSame($loginSessionsBefore, UserLoginSession::query()->count());
    }

    /**
     * Test 6 — no silent fallback. A demo connection that names the main
     * database, or no database at all, stops /demo instead of running
     * against main.
     */
    public function test_the_demo_panel_refuses_to_run_against_the_main_database(): void
    {
        $default = config('database.default');

        config([
            "database.connections.{$default}.database" => 'shared_database',
            'database.connections.demo' => array_merge(
                config("database.connections.{$default}"),
                ['database' => 'shared_database'],
            ),
        ]);

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('resolves to the main database');

        $this->get('/demo/login');
    }

    public function test_the_demo_panel_refuses_to_run_without_a_demo_database(): void
    {
        config(['database.connections.demo.database' => null]);

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DEMO_DB_DATABASE is not set');

        $this->get('/demo/login');
    }

    public function test_an_unreachable_demo_database_fails_demo_without_affecting_admin(): void
    {
        // Point the demo connection at a database that does not exist.
        config(['database.connections.demo.database' => storage_path('framework/testing/missing-demo.sqlite')]);
        DB::purge('demo');

        try {
            DemoCustomer::query()->count();
            $this->fail('Querying an unreachable demo database should fail, not fall back to main.');
        } catch (QueryException|\InvalidArgumentException $exception) {
            $this->assertStringContainsString('missing-demo.sqlite', $exception->getMessage());
        }

        // The main panel keeps working on the main database.
        $this->actingAsPortalUser($this->makeInternalUser('Admin'));
        $this->get('/admin')->assertOk();
    }

    public function test_an_unreachable_demo_database_fails_the_demo_panel_loudly(): void
    {
        $demoUser = $this->makeDemoUser();

        config(['database.connections.demo.database' => storage_path('framework/testing/missing-demo.sqlite')]);
        DB::purge('demo');

        $this->actingAsDemoUser($demoUser);

        $this->get('/demo')->assertServerError();
    }

    public function test_the_panel_can_be_switched_off_without_affecting_admin(): void
    {
        config(['demo.enabled' => false]);

        $this->get('/demo/login')->assertNotFound();

        $this->actingAsPortalUser($this->makeInternalUser('Admin'));
        $this->get('/admin')->assertOk();
    }

    public function test_the_demo_seeder_writes_the_demo_login_only_to_the_demo_database(): void
    {
        config(['demo.user.email' => 'seeded-demo@example.test', 'demo.user.password' => 'Seeded@12345']);
        $mainUsersBefore = User::query()->count();

        $this->seed(DemoDatabaseSeeder::class);

        $this->assertDatabaseHas('demo_users', ['email' => 'seeded-demo@example.test'], 'demo');
        $this->assertDatabaseMissing('users', ['email' => 'seeded-demo@example.test']);
        $this->assertSame($mainUsersBefore, User::query()->count());
        $this->assertGreaterThan(0, DemoCustomer::query()->count());
    }

    public function test_the_demo_seeder_generates_a_password_when_none_is_configured(): void
    {
        config(['demo.user.email' => 'generated@example.test', 'demo.user.password' => null]);

        $this->seed(DemoDatabaseSeeder::class);

        $user = DemoUser::query()->where('email', 'generated@example.test')->firstOrFail();
        $this->assertNotEmpty($user->password);
    }

    public function test_demo_migrate_refuses_a_demo_connection_that_points_at_main(): void
    {
        $default = config('database.default');

        config([
            "database.connections.{$default}.database" => 'shared_database',
            'database.connections.demo' => array_merge(
                config("database.connections.{$default}"),
                ['database' => 'shared_database'],
            ),
        ]);

        $this->artisan('demo:migrate', ['--force' => true])
            ->expectsOutputToContain('resolves to the main database')
            ->assertExitCode(1);
    }

    /**
     * `db:seed --database=demo` switches the runtime default connection
     * to `demo`; the isolation check must still compare against the
     * configured main connection rather than refuse the seed.
     */
    public function test_demo_migrate_with_seed_seeds_only_the_demo_database(): void
    {
        config(['demo.user.email' => 'cli-demo@example.test', 'demo.user.password' => 'Cli@123456']);
        $mainUsersBefore = User::query()->count();

        $this->artisan('demo:migrate', ['--seed' => true, '--force' => true])
            ->expectsOutputToContain('Target: DEMO database')
            ->assertExitCode(0);

        $this->assertDatabaseHas('demo_users', ['email' => 'cli-demo@example.test'], 'demo');
        $this->assertSame($mainUsersBefore, User::query()->count());
        $this->assertGreaterThan(0, DemoCustomer::query()->count());
    }

    public function test_the_plain_migrate_command_never_picks_up_demo_migrations(): void
    {
        foreach (DB::table('migrations')->pluck('migration') as $migration) {
            $this->assertStringNotContainsString('create_demo_', $migration);
        }
    }
}
